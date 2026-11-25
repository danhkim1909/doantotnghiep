/**
 * BrightFuture - Hệ thống WebRTC Mesh Video & Chat Phòng Học Nhóm
 * 
 * Kiến trúc kỹ thuật:
 * - Full-mesh WebRTC: Mỗi thành viên kết nối ngang hàng (Peer-to-Peer) trực tiếp với nhau.
 * - Signaling: Trao đổi SDP Offer, SDP Answer và ICE Candidate qua API PHP MySQL định kỳ (polling).
 * - Device Switching: Hỗ trợ chuyển đổi Camera/Micro trong lúc đang gọi không mất kết nối.
 * - Tab Close / Disconnect Safety: Tự động gửi tín hiệu rời phòng bằng Navigator.sendBeacon khi đóng tab.
 */

(() => {
    'use strict';

    const cfg = window.BF_ROOM_CONFIG;
    if (!cfg) return;

    // --- CÁC PHẦN TỬ GIAO DIỆN CHÍNH ---
    const byId = id => document.getElementById(id);
    const statusEl = byId('callStatus');
    const gridEl = byId('videoGrid');
    const chatLogEl = byId('roomMessages');
    const startCallBtn = byId('startCall');
    const toggleMicBtn = byId('toggleMic');
    const toggleCameraBtn = byId('toggleCamera');
    const shareScreenBtn = byId('shareScreen');
    const audioSelect = byId('audioSource');
    const videoSelect = byId('videoSource');
    const fullscreenBtn = byId('toggleFullscreen');
    const participantsText = byId('participantsText');
    const copyRoomBtn = byId('copyRoom');
    const localVideo = byId('localVideo');

    // --- BIẾN TRẠNG THÁI CUỘC GỌI ---
    const myUserId = Number(cfg.uid);
    const roomId = Number(cfg.id);
    const MAX_PEERS = 5;

    let localStream = null;
    let screenStream = null;
    let lastSignalId = 0;
    let lastChatId = 0;
    let isRequestingMedia = false;
    let isPolling = false;
    let isRoomEnded = false;

    // Quản lý đa tab và thông báo người tham gia
    const currentTabId = 'tab_' + Math.random().toString(36).slice(2) + '_' + Date.now();
    let isDuplicateTab = false;
    let tabChannel = null;
    let duplicateBanner = null;
    const lastKnownParticipants = new Map();

    // Map lưu trữ các kết nối ngang hàng: Map<peerId, { pc: RTCPeerConnection, name: string, offered: boolean }>
    const peerConnections = new Map();
    // Hàng đợi lưu các ICE candidate đến trước khi Remote Description sẵn sàng
    const pendingCandidates = new Map();

    const setStatus = message => {
        if (statusEl) statusEl.textContent = message;
    };

    // --- 1. SAO CHÉP LIÊN KẾT PHÒNG HỌC ---
    if (copyRoomBtn) {
        copyRoomBtn.addEventListener('click', async () => {
            const roomLink = location.origin + cfg.link;
            try {
                await navigator.clipboard.writeText(roomLink);
                copyRoomBtn.textContent = '✓ Đã sao chép';
                setTimeout(() => { copyRoomBtn.textContent = 'Sao chép liên kết phòng'; }, 2500);
            } catch (_) {
                window.prompt('Sao chép liên kết phòng:', roomLink);
            }
        });
    }

    // --- HỆ THỐNG THÔNG BÁO VÀ BẢO VỆ ĐA TAB ---
    function appendSystemChatMessage(text) {
        if (!chatLogEl) return;
        const p = document.createElement('p');
        p.className = 'bf-system-msg';
        p.style.cssText = 'background:#f8fafc;border-left:3px solid #64748b;padding:6px 10px;border-radius:4px;font-size:12px;color:#475569;margin:6px 0;font-style:italic;';
        
        const now = new Date();
        const timeStr = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        
        const small = document.createElement('small');
        small.style.cssText = 'display:block;font-size:10px;color:#94a3b8;font-style:normal;margin-bottom:2px;';
        small.textContent = `Hệ thống · ${timeStr}`;
        
        p.append(small, document.createTextNode(text));
        chatLogEl.append(p);
        while (chatLogEl.children.length > 100) chatLogEl.firstElementChild.remove();
        chatLogEl.scrollTop = chatLogEl.scrollHeight;
    }

    function initMultiTabGuard() {
        if (typeof BroadcastChannel === 'undefined') return;
        try {
            tabChannel = new BroadcastChannel('bf_room_' + roomId + '_' + myUserId);
            tabChannel.onmessage = event => {
                const data = event.data;
                if (!data) return;
                if (data.type === 'ping_active_tab') {
                    if (!isDuplicateTab && data.tabId !== currentTabId) {
                        tabChannel.postMessage({
                            type: 'tab_is_active',
                            tabId: data.tabId,
                            activeTabId: currentTabId
                        });
                    }
                } else if (data.type === 'tab_is_active' && data.tabId === currentTabId) {
                    markAsDuplicateTab();
                } else if (data.type === 'tab_closed' && data.closedTabId !== currentTabId) {
                    if (isDuplicateTab) {
                        restoreFromDuplicateTab();
                    }
                }
            };
            tabChannel.postMessage({ type: 'ping_active_tab', tabId: currentTabId });
        } catch (_) {}
    }

    function markAsDuplicateTab() {
        isDuplicateTab = true;
        if (startCallBtn) startCallBtn.disabled = true;
        setStatus('⚠️ Phòng họp này đang được mở ở một tab khác trên trình duyệt.');
        
        if (!duplicateBanner) {
            duplicateBanner = document.createElement('div');
            duplicateBanner.className = 'bf-note bf-error';
            duplicateBanner.style.cssText = 'margin-bottom:15px;background:#fef2f2;border-left:4px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:8px;font-size:13px;line-height:1.5;';
            duplicateBanner.innerHTML = '<strong>⚠️ Phát hiện phòng đang mở ở tab khác:</strong> Tài khoản của bạn đã tham gia cuộc gọi này ở một tab khác trên trình duyệt. Để tránh xung đột âm thanh, hình ảnh và tín hiệu kết nối, vui lòng quay lại tab đang mở hoặc đóng tab cũ trước khi sử dụng tại đây.';
            const container = byId('callStatus')?.parentNode;
            if (container) {
                container.insertBefore(duplicateBanner, byId('callStatus'));
            }
        }
    }

    function restoreFromDuplicateTab() {
        isDuplicateTab = false;
        if (startCallBtn) startCallBtn.disabled = false;
        if (duplicateBanner) {
            duplicateBanner.remove();
            duplicateBanner = null;
        }
        setStatus('Tab trước đã đóng. Bạn có thể sử dụng phòng họp tại tab này.');
    }

    // --- 2. GIAO TIẾP VỚI BACKEND API (SIGNALING & ACTIONS) ---
    async function postApi(action, extraFields = {}) {
        const formData = new URLSearchParams({
            ...extraFields,
            action,
            room: roomId,
            csrf_token: cfg.csrf
        });

        const response = await fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });

        let json;
        try {
            json = await response.json();
        } catch (_) {
            throw new Error('Không đọc được phản hồi từ máy chủ.');
        }

        if (!response.ok || !json.ok) {
            throw new Error(json.message || `Lỗi máy chủ (${response.status})`);
        }
        return json;
    }

    // --- 3. QUẢN LÝ KHUNG VIDEO CỦA CÁC THÀNH VIÊN (REMOTE TILES) ---
    function getOrCreateRemoteTile(peerId, peerName) {
        let tile = byId('remote-' + peerId);
        if (tile) return tile;

        tile = document.createElement('div');
        tile.className = 'bf-video-wrap';
        tile.id = 'remote-' + peerId;

        const placeholder = document.createElement('div');
        placeholder.className = 'bf-placeholder';
        placeholder.textContent = 'Đang kết nối hình ảnh...';

        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;

        const label = document.createElement('label');
        label.textContent = peerName || `Thành viên #${peerId}`;

        tile.append(placeholder, video, label);
        if (gridEl) gridEl.append(tile);
        return tile;
    }

    function removePeer(peerId) {
        const peer = peerConnections.get(peerId);
        if (peer) {
            peer.pc.onicecandidate = null;
            peer.pc.ontrack = null;
            peer.pc.close();
            peerConnections.delete(peerId);
        }
        const tile = byId('remote-' + peerId);
        if (tile) tile.remove();
        pendingCandidates.delete(peerId);
    }

    async function sendSignal(toPeerId, kind, data) {
        try {
            await postApi('signal', {
                to: toPeerId,
                kind,
                payload: JSON.stringify(data)
            });
        } catch (err) {
            setStatus(err.message);
        }
    }

    // --- 4. KHỞI TẠO VÀ BẮT TAY KẾT NỐI WEBRTC PEER ---
    function getPeerConnection(peerId, peerName) {
        peerId = Number(peerId);
        if (peerConnections.has(peerId)) {
            return peerConnections.get(peerId);
        }

        // Cấu hình STUN server miễn phí của Google để vượt tường lửa / NAT
        const pc = new RTCPeerConnection({
            iceServers: [
                { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }
            ]
        });

        const peerItem = { pc, name: peerName, offered: false };
        peerConnections.set(peerId, peerItem);

        // Khi tìm thấy ứng viên mạng (ICE Candidate), gửi tới peer qua máy chủ
        pc.onicecandidate = event => {
            if (event.candidate) {
                void sendSignal(peerId, 'candidate', event.candidate.toJSON());
            }
        };

        // Khi nhận được luồng hình ảnh/âm thanh từ thành viên khác
        pc.ontrack = event => {
            const tile = getOrCreateRemoteTile(peerId, peerName);
            const video = tile.querySelector('video');
            let stream = event.streams[0];
            if (!stream) {
                stream = video.srcObject || new MediaStream();
                stream.addTrack(event.track);
            }
            video.srcObject = stream;
            video.play().catch(() => {});
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'failed') {
                setStatus('Một kết nối gặp lỗi mạng. Hãy tải lại trang để kết nối lại.');
            }
        };

        // Nếu đã có luồng stream thiết bị tại máy mình, gắn các track vào kết nối
        if (localStream) {
            for (const track of localStream.getTracks()) {
                pc.addTrack(track, localStream);
            }
        }

        return peerItem;
    }

    async function sendOffer(peerId, peerName, force = false) {
        const peerItem = getPeerConnection(peerId, peerName);
        if (peerItem.offered && !force) return;
        if (peerItem.pc.signalingState !== 'stable') return;

        try {
            peerItem.offered = true;
            const offer = await peerItem.pc.createOffer();
            await peerItem.pc.setLocalDescription(offer);
            await sendSignal(peerId, 'offer', peerItem.pc.localDescription.toJSON());
        } catch (err) {
            setStatus('Không thể tạo lời mời kết nối: ' + err.message);
        }
    }

    async function handleIncomingSignal(signal) {
        const senderId = Number(signal.sender_id);
        if (senderId === myUserId) return;

        let payload;
        try {
            payload = JSON.parse(signal.payload);
        } catch (_) {
            return;
        }

        const peerItem = getPeerConnection(senderId, `Thành viên #${senderId}`);
        const pc = peerItem.pc;

        try {
            if (signal.kind === 'offer') {
                if (pc.signalingState !== 'stable') {
                    await pc.setLocalDescription({ type: 'rollback' });
                }
                await pc.setRemoteDescription(new RTCSessionDescription(payload));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await sendSignal(senderId, 'answer', pc.localDescription.toJSON());
            } else if (signal.kind === 'answer') {
                if (pc.signalingState === 'have-local-offer') {
                    await pc.setRemoteDescription(new RTCSessionDescription(payload));
                }
            } else if (signal.kind === 'candidate') {
                if (!pc.remoteDescription) {
                    if (!pendingCandidates.has(senderId)) pendingCandidates.set(senderId, []);
                    pendingCandidates.get(senderId).push(payload);
                    return;
                }
                await pc.addIceCandidate(new RTCIceCandidate(payload));
            }

            // Xử lý các candidate bị xếp hàng đợi trước đó
            if (pc.remoteDescription && pendingCandidates.has(senderId)) {
                for (const candidate of pendingCandidates.get(senderId)) {
                    await pc.addIceCandidate(new RTCIceCandidate(candidate));
                }
                pendingCandidates.delete(senderId);
            }
        } catch (err) {
            setStatus('Sự cố khi thiết lập cuộc gọi: ' + err.message);
        }
    }

    // --- 5. ĐỔI THIẾT BỊ MEDIA (CAMERA & MICRO) KHÔNG NGẮT KẾT NỐI ---
    async function updateDeviceList() {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            if (audioSelect) {
                audioSelect.innerHTML = '';
                const audioInputs = devices.filter(d => d.kind === 'audioinput');
                audioInputs.forEach((d, idx) => {
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.textContent = d.label || `Microphone ${idx + 1}`;
                    audioSelect.appendChild(opt);
                });
                audioSelect.style.display = audioInputs.length > 1 ? 'inline-block' : 'none';
            }
            if (videoSelect) {
                videoSelect.innerHTML = '';
                const videoInputs = devices.filter(d => d.kind === 'videoinput');
                videoInputs.forEach((d, idx) => {
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.textContent = d.label || `Camera ${idx + 1}`;
                    videoSelect.appendChild(opt);
                });
                videoSelect.style.display = videoInputs.length > 1 ? 'inline-block' : 'none';
            }
        } catch (_) {}
    }

    async function switchAudioDevice(deviceId) {
        if (!localStream) return;
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                audio: { deviceId: { exact: deviceId } }
            });
            const newTrack = newStream.getAudioTracks()[0];
            const oldTrack = localStream.getAudioTracks()[0];

            if (oldTrack) {
                oldTrack.stop();
                localStream.removeTrack(oldTrack);
            }
            localStream.addTrack(newTrack);

            // Thay thế track âm thanh trên tất cả các kết nối WebRTC hiện có
            for (const { pc } of peerConnections.values()) {
                const sender = pc.getSenders().find(s => s.track && s.track.kind === 'audio');
                if (sender) await sender.replaceTrack(newTrack);
            }
            setStatus('Đã đổi micro thành công.');
        } catch (e) {
            setStatus('Không thể đổi micro: ' + e.message);
        }
    }

    async function switchVideoDevice(deviceId) {
        if (!localStream) return;
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: { deviceId: { exact: deviceId } }
            });
            const newTrack = newStream.getVideoTracks()[0];
            const oldTrack = localStream.getVideoTracks()[0];

            if (oldTrack) {
                oldTrack.stop();
                localStream.removeTrack(oldTrack);
            }
            localStream.addTrack(newTrack);

            if (localVideo) localVideo.srcObject = localStream;

            // Thay thế track hình ảnh trên tất cả các kết nối WebRTC hiện có
            for (const { pc } of peerConnections.values()) {
                const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                if (sender) await sender.replaceTrack(newTrack);
            }
            setStatus('Đã đổi camera thành công.');
        } catch (e) {
            setStatus('Không thể đổi camera: ' + e.message);
        }
    }

    if (audioSelect) {
        audioSelect.addEventListener('change', () => {
            if (audioSelect.value) void switchAudioDevice(audioSelect.value);
        });
    }
    if (videoSelect) {
        videoSelect.addEventListener('change', () => {
            if (videoSelect.value) void switchVideoDevice(videoSelect.value);
        });
    }

    // --- 6. VÒNG ĐỜI VÀ GỬI TÍN HIỆU RỜI PHÒNG KHI ĐÓNG TAB ---
    function sendLeaveBeacon() {
        if (isRoomEnded || isDuplicateTab) return;
        if (tabChannel) {
            try {
                tabChannel.postMessage({ type: 'tab_closed', closedTabId: currentTabId });
            } catch (_) {}
        }
        const formData = new FormData();
        formData.append('action', 'leave');
        formData.append('room', String(roomId));
        formData.append('csrf_token', cfg.csrf);
        // sendBeacon gửi dữ liệu nền ngay cả khi trình duyệt vừa tắt tab
        if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.endpoint, formData);
        }
    }

    window.addEventListener('pagehide', sendLeaveBeacon);

    // Cảnh báo người dùng khi đang gọi mà đóng tab hoặc tải lại trang
    window.addEventListener('beforeunload', event => {
        sendLeaveBeacon();
        if (!isDuplicateTab && (localStream || peerConnections.size > 0)) {
            event.preventDefault();
            event.returnValue = ''; // Bắt buộc cho Chrome / Edge
            return '';
        }
    });

    // Cảnh báo người dùng khi đang gọi mà bấm sang menu khác trên giao diện
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        
        if (!isDuplicateTab && (localStream || peerConnections.size > 0)) {
            const confirmed = window.confirm('Bạn đang tham gia cuộc gọi nhóm. Bạn có chắc chắn muốn rời phòng để chuyển sang trang khác?');
            if (!confirmed) {
                event.preventDefault();
                event.stopPropagation();
            }
        }
    }, true);

    // --- 7. ĐỒNG BỘ ĐỊNH KỲ (SIGNALING & CHAT POLLING) ---
    async function refreshRoomData() {
        if (isRoomEnded || isPolling || isDuplicateTab) return;
        isPolling = true;

        try {
            const params = new URLSearchParams({
                room: roomId,
                after: lastSignalId,
                chat_after: lastChatId
            });

            const res = await fetch(cfg.endpoint + '?' + params, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (res.status === 410 || res.status === 403) {
                isRoomEnded = true;
                setStatus('Phòng họp đã kết thúc hoặc bạn đã rời phòng.');
                return;
            }
            if (!res.ok) throw new Error('Không thể kết nối máy chủ.');

            const data = await res.json();
            if (!data.ok) throw new Error('Lỗi tải dữ liệu phòng.');

            // Cập nhật danh sách thành viên đang trực tuyến
            const currentParticipants = data.participants || [];
            const activeUserIds = new Set(currentParticipants.map(p => Number(p.id)));
            const names = currentParticipants.map(p => p.full_name);
            if (participantsText) {
                participantsText.textContent = names.join(' · ') || 'Chưa có người tham gia';
            }

            // Thông báo người tham gia / rời phòng trong khung chat
            if (lastKnownParticipants.size > 0) {
                for (const p of currentParticipants) {
                    const pid = Number(p.id);
                    if (pid !== myUserId && !lastKnownParticipants.has(pid)) {
                        appendSystemChatMessage(`🟢 ${p.full_name} đã tham gia phòng họp.`);
                    }
                }
                for (const [pid, pName] of lastKnownParticipants.entries()) {
                    if (pid !== myUserId && !activeUserIds.has(pid)) {
                        appendSystemChatMessage(`🔴 ${pName} đã rời phòng họp.`);
                    }
                }
            }
            lastKnownParticipants.clear();
            for (const p of currentParticipants) {
                lastKnownParticipants.set(Number(p.id), p.full_name);
            }

            // Dọn dẹp khung video của những ai đã thoát phòng
            for (const [peerId] of peerConnections) {
                if (!activeUserIds.has(peerId)) removePeer(peerId);
            }

            // Xử lý các tín hiệu WebRTC mới
            for (const s of data.signals || []) {
                lastSignalId = Math.max(lastSignalId, Number(s.id));
                await handleIncomingSignal(s);
            }

            // Xử lý tin nhắn chat mới trong phòng
            for (const msg of data.messages || []) {
                lastChatId = Math.max(lastChatId, Number(msg.id));
                const p = document.createElement('p');
                const small = document.createElement('small');
                small.textContent = `${msg.sender} · ${msg.created_at}`;
                p.append(small, document.createTextNode(msg.body));
                if (chatLogEl) {
                    chatLogEl.append(p);
                    while (chatLogEl.children.length > 100) chatLogEl.firstElementChild.remove();
                    chatLogEl.scrollTop = chatLogEl.scrollHeight;
                }
            }

            // Nếu mình đã bật camera/micro, chủ động gửi Offer cho người mới vào phòng
            if (localStream) {
                for (const p of data.participants || []) {
                    const id = Number(p.id);
                    if (id !== myUserId && !peerConnections.has(id) && peerConnections.size < MAX_PEERS) {
                        getPeerConnection(id, p.full_name);
                        // Tránh 2 bên cùng gửi Offer bằng quy ước: user ID nhỏ hơn gửi trước
                        if (myUserId < id) {
                            await sendOffer(id, p.full_name);
                        }
                    }
                }
            }
        } catch (err) {
            setStatus(err.message);
        } finally {
            isPolling = false;
        }
    }

    // --- 8. ĐIỀU KHIỂN NÚT BẤM (BẬT/TẮT CAMERA, MICRO, CHIA SẺ MÀN HÌNH) ---
    if (startCallBtn) {
        startCallBtn.addEventListener('click', async () => {
            if (isRequestingMedia) return;
            isRequestingMedia = true;

            try {
                if (localStream) {
                    // Tắt thiết bị và kết thúc phiên gọi
                    localStream.getTracks().forEach(t => t.stop());
                    localStream = null;
                    for (const [id] of peerConnections) removePeer(id);
                    if (localVideo) localVideo.srcObject = null;

                    startCallBtn.textContent = 'Bật camera / micro';
                    if (toggleMicBtn) toggleMicBtn.disabled = true;
                    if (toggleCameraBtn) toggleCameraBtn.disabled = true;
                    if (shareScreenBtn) shareScreenBtn.disabled = true;
                    if (audioSelect) audioSelect.style.display = 'none';
                    if (videoSelect) videoSelect.style.display = 'none';

                    setStatus('Đã tắt kết nối thiết bị.');
                    return;
                }

                if (!navigator.mediaDevices?.getUserMedia) {
                    throw new Error('Trình duyệt yêu cầu HTTPS hoặc localhost để truy cập camera/micro.');
                }

                localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true })
                    .catch(async () => navigator.mediaDevices.getUserMedia({ audio: true, video: false }));

                if (localVideo) {
                    localVideo.srcObject = localStream;
                    await localVideo.play().catch(() => {});
                }

                startCallBtn.textContent = 'Tắt kết nối thiết bị';
                if (toggleMicBtn) {
                    toggleMicBtn.disabled = !localStream.getAudioTracks().length;
                    toggleMicBtn.textContent = 'Tắt micro';
                }
                if (toggleCameraBtn) {
                    toggleCameraBtn.disabled = !localStream.getVideoTracks().length;
                    toggleCameraBtn.textContent = 'Tắt camera';
                }
                if (shareScreenBtn) {
                    shareScreenBtn.disabled = !localStream.getVideoTracks().length;
                }

                setStatus('Đã kết nối camera & micro. Đang đồng bộ cùng thành viên...');
                await updateDeviceList();

                for (const [id] of peerConnections) removePeer(id);
                await refreshRoomData();
            } catch (err) {
                setStatus('Không thể bật thiết bị: ' + err.message + ' (vui lòng kiểm tra quyền truy cập).');
            } finally {
                isRequestingMedia = false;
            }
        });
    }

    if (toggleMicBtn) {
        toggleMicBtn.addEventListener('click', () => {
            if (!localStream) return;
            const audioTrack = localStream.getAudioTracks()[0];
            if (!audioTrack) return;
            audioTrack.enabled = !audioTrack.enabled;
            toggleMicBtn.textContent = audioTrack.enabled ? 'Tắt micro' : '🎤 Bật micro';
            toggleMicBtn.style.background = audioTrack.enabled ? '' : '#fee2e2';
        });
    }

    if (toggleCameraBtn) {
        toggleCameraBtn.addEventListener('click', () => {
            if (!localStream) return;
            const videoTrack = localStream.getVideoTracks()[0];
            if (!videoTrack) return;
            videoTrack.enabled = !videoTrack.enabled;
            toggleCameraBtn.textContent = videoTrack.enabled ? 'Tắt camera' : '📹 Bật camera';
            toggleCameraBtn.style.background = videoTrack.enabled ? '' : '#fee2e2';
        });
    }

    if (shareScreenBtn) {
        shareScreenBtn.addEventListener('click', async () => {
            if (!localStream) return;
            try {
                async function restoreCamera() {
                    const originalCameraTrack = localStream.getVideoTracks()[0] || null;
                    for (const { pc } of peerConnections.values()) {
                        const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                        if (sender) await sender.replaceTrack(originalCameraTrack);
                    }
                    if (localVideo) localVideo.srcObject = localStream;
                    shareScreenBtn.textContent = '🖥️ Chia sẻ màn hình';
                    shareScreenBtn.style.background = '';
                    shareScreenBtn.style.color = '';
                    if (screenStream) {
                        screenStream.getTracks().forEach(t => t.stop());
                        screenStream = null;
                    }
                }

                if (screenStream) {
                    await restoreCamera();
                    return;
                }

                if (!navigator.mediaDevices?.getDisplayMedia) {
                    throw new Error('Trình duyệt hiện tại không hỗ trợ getDisplayMedia.');
                }

                screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                const screenTrack = screenStream.getVideoTracks()[0];
                if (!screenTrack) {
                    throw new Error('Không nhận được luồng hình ảnh màn hình.');
                }

                // Gửi màn hình tới tất cả các peer đang kết nối
                for (const { pc } of peerConnections.values()) {
                    const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                    if (sender) await sender.replaceTrack(screenTrack);
                }
                if (localVideo) localVideo.srcObject = screenStream;

                screenTrack.addEventListener('ended', () => { void restoreCamera(); }, { once: true });
                shareScreenBtn.textContent = '⏹️ Dừng chia sẻ';
                shareScreenBtn.style.background = '#fef2f2';
                shareScreenBtn.style.color = '#991b1b';
            } catch (err) {
                if (err.name !== 'NotAllowedError') {
                    setStatus('Không thể chia sẻ màn hình: ' + err.message);
                }
            }
        });
    }

    // Nút phóng to / thu nhỏ toàn màn hình
    if (fullscreenBtn && gridEl) {
        fullscreenBtn.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                gridEl.requestFullscreen().catch(() => {});
                fullscreenBtn.textContent = 'Thu nhỏ';
            } else {
                document.exitFullscreen().catch(() => {});
                fullscreenBtn.textContent = 'Toàn màn hình';
            }
        });
    }

    // Gửi tin nhắn trong phòng
    const chatForm = byId('roomMessageForm');
    const chatInput = byId('roomMessageInput');
    if (chatForm && chatInput) {
        chatForm.addEventListener('submit', async event => {
            event.preventDefault();
            const text = chatInput.value.trim();
            if (!text) return;
            try {
                await postApi('chat', { body: text });
                chatInput.value = '';
                await refreshRoomData();
            } catch (err) {
                setStatus(err.message);
            }
        });
    }

    // Khởi chạy hệ thống bảo vệ đa tab và vòng lặp đồng bộ tín hiệu
    initMultiTabGuard();
    setStatus('Phòng đã sẵn sàng. Bấm “Bật camera / micro” để bắt đầu học nhóm.');
    setInterval(refreshRoomData, 1800);
    void refreshRoomData();
})();
