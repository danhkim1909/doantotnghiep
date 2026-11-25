<?php
/** Simple small-group WebRTC meeting room; signaling is stored in MySQL. */
require_once __DIR__.'/includes/portal_ui.php';
$user = require_login();
$uid = (int)$user['id'];
$pdo = db();
$errors = [];
$roomCode = strtoupper(trim(get_query('room')));
$room = null;
$waitingRoom = null;
$waitingRoomParticipantsCount = 0;
$participants = [];

if (is_post()) {
    require_csrf();
    $action = get_post('action');
    try {
        if ($action === 'create') {
            $title = clean_text(get_post('title'), 150);
            $password = (string)($_POST['password'] ?? '');
            if (bf_social_len($title) < 3) throw new DomainException('Tên phòng cần ít nhất 3 ký tự.');
            if (strlen($password) > 100) throw new DomainException('Mật khẩu phòng quá dài.');
            $code = strtoupper(bin2hex(random_bytes(6)));
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO bf_meeting_rooms(code,title,host_id,password_hash) VALUES (?,?,?,?)')
                    ->execute([$code, $title, $uid, $password === '' ? null : password_hash($password, PASSWORD_DEFAULT)]);
                $rid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO bf_meeting_participants(room_id,user_id) VALUES (?,?)')
                    ->execute([$rid, $uid]);
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            redirect('rooms.php?room='.$code, 303);
        } elseif ($action === 'join') {
            $code = strtoupper(trim(get_post('code')));
            $password = (string)($_POST['password'] ?? '');
            $s = $pdo->prepare('SELECT id,password_hash,status FROM bf_meeting_rooms WHERE code=? LIMIT 1');
            $s->execute([$code]);
            $found = $s->fetch();
            if (!$found || $found['status'] !== 'open') throw new DomainException('Không tìm thấy phòng đang hoạt động.');
            if ($found['password_hash'] && !password_verify($password, $found['password_hash'])) throw new DomainException('Mật khẩu phòng không đúng.');
            $rid = (int)$found['id'];
            $check = $pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');
            $check->execute([$rid, $uid]);
            if (!$check->fetchColumn()) {
                $count = $pdo->prepare('SELECT COUNT(*) FROM bf_meeting_participants WHERE room_id=? AND last_seen>=NOW()-INTERVAL 25 SECOND');
                $count->execute([$rid]);
                if ((int)$count->fetchColumn() >= 6) throw new DomainException('Phòng đã đủ 6 thành viên đang hoạt động.');
            }
            $pdo->prepare('INSERT INTO bf_meeting_participants(room_id,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE last_seen=NOW()')
                ->execute([$rid, $uid]);
            redirect('rooms.php?room='.$code, 303);
        } elseif (in_array($action, ['leave', 'end'], true)) {
            $rid = positive_int($_POST['room_id'] ?? 0);
            $s = $pdo->prepare('SELECT host_id FROM bf_meeting_rooms WHERE id=?');
            $s->execute([$rid]);
            $host = (int)$s->fetchColumn();
            if ($action === 'end' && $host === $uid) {
                $pdo->prepare('UPDATE bf_meeting_rooms SET status="ended",ended_at=NOW() WHERE id=?')->execute([$rid]);
                $pdo->prepare('DELETE FROM bf_meeting_participants WHERE room_id=?')->execute([$rid]);
            } elseif ($action === 'leave') {
                $pdo->prepare('DELETE FROM bf_meeting_participants WHERE room_id=? AND user_id=?')->execute([$rid, $uid]);
            } else {
                throw new DomainException('Bạn không được phép kết thúc phòng.');
            }
            redirect('rooms.php', 303);
        } else {
            throw new DomainException('Thao tác không hợp lệ.');
        }
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('[rooms] '.$ex->getMessage());
        $errors[] = 'Không thể xử lý phòng họp. Hãy nhập SQL bổ sung trước khi sử dụng.';
    }
}

try {
    if (preg_match('/^[A-F0-9]{12}$/', $roomCode)) {
        $s = $pdo->prepare('SELECT r.*, u.full_name AS host_name FROM bf_meeting_rooms r JOIN users u ON u.id=r.host_id WHERE r.code=?');
        $s->execute([$roomCode]);
        $foundRoom = $s->fetch() ?: null;
        if ($foundRoom && $foundRoom['status'] === 'open') {
            $s = $pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');
            $s->execute([(int)$foundRoom['id'], $uid]);
            if ($s->fetchColumn()) {
                $room = $foundRoom;
            } else {
                $waitingRoom = $foundRoom;
                $activeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM bf_meeting_participants WHERE room_id=? AND last_seen>=NOW()-INTERVAL 25 SECOND');
                $activeCountStmt->execute([(int)$waitingRoom['id']]);
                $waitingRoomParticipantsCount = (int)$activeCountStmt->fetchColumn();
            }
        } elseif ($foundRoom) {
            $errors[] = 'Cuộc họp đã kết thúc.';
        } else {
            $errors[] = 'Không tìm thấy phòng họp với mã '.e($roomCode).'.';
        }
    }
    $s = $pdo->prepare('SELECT r.code,r.title,r.created_at FROM bf_meeting_rooms r JOIN bf_meeting_participants p ON p.room_id=r.id WHERE p.user_id=? AND r.status="open" ORDER BY r.id DESC LIMIT 20');
    $s->execute([$uid]);
    $myRooms = $s->fetchAll();
} catch (Throwable $ex) {
    error_log('[rooms read] '.$ex->getMessage());
    $errors[] = 'Chức năng phòng họp chưa có dữ liệu. Vui lòng nhập database/20261029_social_meetings.sql.';
    $myRooms = [];
}

bf_portal_begin($user, 'rooms', 'Phòng gọi nhóm');
?>
<header class="bf-header">
    <div>
        <h1>Phòng gọi Video / Audio</h1>
        <p>Học tập cùng bạn bè qua âm thanh, hình ảnh, chia sẻ màn hình và chat thời gian thực.</p>
    </div>
    <a class="bf-muted" href="<?= e(app_url('social.php')) ?>">← Trở về cộng đồng</a>
</header>

<?php foreach ($errors as $error): ?>
    <div class="bf-note bf-error" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($room): ?>
<!-- ========================================== -->
<!-- 1. PHÒNG GỌI CHÍNH (IN-CALL WEBRTC ROOM) -->
<!-- ========================================== -->
<div class="bf-header">
    <div>
        <h2 style="margin:0"><?= e($room['title']) ?></h2>
        <p class="bf-subtle">Chủ phòng: <?= e($room['host_name']) ?> · Mã phòng: <strong><?= e($room['code']) ?></strong> · Hỗ trợ tối đa 6 tài khoản đang hoạt động</p>
    </div>
    <div class="bf-actions">
        <button type="button" class="bf-muted" id="copyRoom">Sao chép liên kết phòng</button>
        <form method="post" onsubmit="return confirm('<?= ((int)$room['host_id'] === $uid ? 'Kết thúc cuộc họp cho tất cả mọi người?' : 'Rời phòng họp?') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
            <input type="hidden" name="action" value="<?= ((int)$room['host_id'] === $uid ? 'end' : 'leave') ?>">
            <button class="bf-muted bf-danger"><?= ((int)$room['host_id'] === $uid ? 'Kết thúc phòng' : 'Rời phòng') ?></button>
        </form>
    </div>
</div>
<div class="bf-note">Trình duyệt sẽ yêu cầu quyền micro/camera. Gọi video cần <b>localhost</b> hoặc HTTPS; kết nối WebRTC trực tiếp có thể bị giới hạn bởi mạng/NAT. Chức năng ghi âm/ghi hình cuộc họp chưa được hỗ trợ.</div>
<div class="bf-cols">
    <section class="bf-panel">
        <div id="callStatus" class="bf-subtle" aria-live="polite">Đang khởi tạo cuộc họp...</div>
        <div class="bf-call-bar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:15px 0">
            <button id="startCall" class="bf-btn" type="button">Bật camera / micro</button>
            <button id="toggleMic" class="bf-muted" type="button" disabled>Micro</button>
            <button id="toggleCamera" class="bf-muted" type="button" disabled>Camera</button>
            <button id="shareScreen" class="bf-muted" type="button" disabled>Chia sẻ màn hình</button>
            <select id="audioSource" class="bf-input" style="max-width:160px;height:38px;padding:0 8px;display:none;font-size:12px" title="Đổi Micro"></select>
            <select id="videoSource" class="bf-input" style="max-width:160px;height:38px;padding:0 8px;display:none;font-size:12px" title="Đổi Camera"></select>
            <button id="toggleFullscreen" class="bf-muted" type="button" title="Toàn màn hình">Toàn màn hình</button>
        </div>
        <div id="videoGrid" class="bf-call-grid">
            <div class="bf-video-wrap" id="localTile">
                <div class="bf-placeholder">Bật camera để bắt đầu</div>
                <video id="localVideo" autoplay muted playsinline></video>
                <label>Bạn (<?= e($user['full_name']) ?>)</label>
            </div>
        </div>
        <h3>Thành viên đang kết nối</h3>
        <p id="participantsText" class="bf-subtle">Đang cập nhật...</p>
    </section>
    <section class="bf-panel">
        <h2>Chat trong phòng</h2>
        <div class="bf-room-chat" id="roomMessages" aria-live="polite"></div>
        <form id="roomMessageForm" class="bf-message-form">
            <input class="bf-input" maxlength="2000" required id="roomMessageInput" placeholder="Nhắn tin trong phòng...">
            <button class="bf-btn">Gửi</button>
        </form>
    </section>
</div>
<script>
window.BF_ROOM_CONFIG = {
    id: <?= (int)$room['id'] ?>,
    uid: <?= $uid ?>,
    host: <?= (int)$room['host_id'] ?>,
    endpoint: <?= json_encode(app_url('api/room-events.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    link: <?= json_encode(app_url('rooms.php?room='.$room['code'])) ?>
};
</script>
<script src="<?= e(app_url('js/bf-meeting.js')) ?>" defer></script>

<?php elseif ($waitingRoom): ?>
<!-- ========================================== -->
<!-- 2. PHÒNG CHỜ (LOBBY / GREEN ROOM)          -->
<!-- ========================================== -->
<div class="bf-header">
    <div>
        <h2 style="margin:0"><?= e($waitingRoom['title']) ?></h2>
        <p class="bf-subtle">Phòng chờ cuộc họp · Chủ phòng: <strong><?= e($waitingRoom['host_name']) ?></strong> · Mã phòng: <strong><?= e($waitingRoom['code']) ?></strong></p>
    </div>
    <div class="bf-actions">
        <a class="bf-muted" href="rooms.php">← Rời phòng chờ</a>
    </div>
</div>

<div class="bf-cols" style="margin-top: 18px;">
    <!-- CỘT TRÁI: KIỂM TRA THIẾT BỊ (PREVIEW CAM & MIC) -->
    <section class="bf-panel" style="flex: 1.2;">
        <h3 style="margin-top:0">Kiểm tra camera & micro trước khi vào</h3>
        <p class="bf-subtle" style="font-size:13px; margin-bottom:14px;">Chỉnh góc máy, ánh sáng và kiểm tra âm thanh của bạn trước khi người khác nhìn thấy.</p>
        
        <div style="position:relative; width:100%; border-radius:12px; overflow:hidden; background:#0f172a; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center;">
            <video id="previewVideo" autoplay muted playsinline style="width:100%; height:100%; object-fit:cover; transform:scaleX(-1);"></video>
            <div id="previewPlaceholder" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; font-size:14px; gap:8px;">
                <span style="font-size:32px;">📹</span>
                <span>Camera đang tắt hoặc chưa cấp quyền</span>
            </div>
        </div>

        <div style="display:flex; gap:12px; justify-content:center; margin-top:16px;">
            <button type="button" id="btnPreviewMic" class="bf-muted" style="border-radius:24px; padding:10px 18px; font-weight:600; font-size:13px; cursor:pointer;">
                🎤 Micro đang bật
            </button>
            <button type="button" id="btnPreviewCam" class="bf-muted" style="border-radius:24px; padding:10px 18px; font-weight:600; font-size:13px; cursor:pointer;">
                📹 Camera đang bật
            </button>
        </div>
        <p class="bf-subtle" style="text-align:center; font-size:12px; margin-top:10px;">Bạn có thể bật hoặc tắt camera/micro bất kỳ lúc nào sau khi vào phòng.</p>
    </section>

    <!-- CỘT PHẢI: THÔNG TIN PHÒNG & THAM GIA -->
    <section class="bf-panel" style="flex: 0.9; display:flex; flex-direction:column; justify-content:space-between;">
        <div>
            <h3 style="margin-top:0">Sẵn sàng tham gia?</h3>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin:16px 0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:13px;">
                    <span class="bf-subtle">Trạng thái:</span>
                    <span style="color:#16a34a; font-weight:700;">● Đang mở cuộc họp</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:13px;">
                    <span class="bf-subtle">Thành viên trực tuyến:</span>
                    <strong><?= $waitingRoomParticipantsCount ?> / 6 người</strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:13px;">
                    <span class="bf-subtle">Bảo mật:</span>
                    <span><?= !empty($waitingRoom['password_hash']) ? '🔒 Có mật khẩu phòng' : '🔓 Phòng công khai' ?></span>
                </div>
            </div>

            <form method="post" id="joinRoomForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="join">
                <input type="hidden" name="code" value="<?= e($waitingRoom['code']) ?>">
                <?php if (!empty($waitingRoom['password_hash'])): ?>
                    <label class="bf-field" style="margin-bottom:16px;">
                        <span>🔒 Mật khẩu phòng *</span>
                        <input name="password" type="password" class="bf-input" required autocomplete="off" placeholder="Nhập mật khẩu để vào phòng">
                    </label>
                <?php endif; ?>
                <button type="submit" class="bf-btn" style="width:100%; padding:13px; font-size:15px; font-weight:700;">
                    → Tham gia cuộc họp ngay
                </button>
            </form>
        </div>

        <div style="margin-top:20px; text-align:center;">
            <a class="bf-muted" href="rooms.php" style="font-size:13px;">Hủy bỏ và quay lại danh sách phòng</a>
        </div>
    </section>
</div>

<script>
(function() {
    const video = document.getElementById('previewVideo');
    const placeholder = document.getElementById('previewPlaceholder');
    const btnCam = document.getElementById('btnPreviewCam');
    const btnMic = document.getElementById('btnPreviewMic');
    let stream = null;
    let camEnabled = true;
    let micEnabled = true;

    async function initPreview() {
        if (!navigator.mediaDevices?.getUserMedia) {
            if (placeholder) placeholder.textContent = 'Trình duyệt không hỗ trợ camera/micro trên kết nối này.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
                .catch(async () => navigator.mediaDevices.getUserMedia({ video: false, audio: true }));
            if (video && stream.getVideoTracks().length) {
                video.srcObject = stream;
                if (placeholder) placeholder.style.display = 'none';
            }
        } catch (_) {
            if (placeholder) placeholder.textContent = 'Chưa cấp quyền truy cập camera/micro.';
        }
    }

    if (btnCam) {
        btnCam.addEventListener('click', () => {
            if (!stream) return;
            const track = stream.getVideoTracks()[0];
            if (!track) return;
            camEnabled = !camEnabled;
            track.enabled = camEnabled;
            btnCam.style.background = camEnabled ? '' : '#fee2e2';
            btnCam.style.color = camEnabled ? '' : '#991b1b';
            btnCam.textContent = camEnabled ? '📹 Camera đang bật' : '📹 Camera đang tắt';
            if (placeholder) placeholder.style.display = camEnabled ? 'none' : 'flex';
        });
    }

    if (btnMic) {
        btnMic.addEventListener('click', () => {
            if (!stream) return;
            const track = stream.getAudioTracks()[0];
            if (!track) return;
            micEnabled = !micEnabled;
            track.enabled = micEnabled;
            btnMic.style.background = micEnabled ? '' : '#fee2e2';
            btnMic.style.color = micEnabled ? '' : '#991b1b';
            btnMic.textContent = micEnabled ? '🎤 Micro đang bật' : '🎤 Micro đang tắt';
        });
    }

    const form = document.getElementById('joinRoomForm');
    if (form) {
        form.addEventListener('submit', () => {
            if (stream) {
                stream.getTracks().forEach(t => t.stop());
                stream = null;
            }
        });
    }

    window.addEventListener('beforeunload', () => {
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
    });

    void initPreview();
})();
</script>

<?php else: ?>
<!-- ========================================== -->
<!-- 3. SẢNH CHUNG (LOBBY TẠO & TÌM PHÒNG)     -->
<!-- ========================================== -->
<div class="bf-grid">
    <section class="bf-panel">
        <h2>Tạo phòng mới</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="bf-field">
                Tên phòng
                <input name="title" class="bf-input" required minlength="3" maxlength="150" placeholder="Ví dụ: Thảo luận đồ án Python">
            </label>
            <label class="bf-field">
                Mật khẩu phòng (không bắt buộc)
                <input name="password" class="bf-input" maxlength="100" type="password" autocomplete="new-password" placeholder="Để trống nếu không đặt mật khẩu">
            </label>
            <button class="bf-btn">+ Tạo phòng họp</button>
        </form>
    </section>
    <section class="bf-panel">
        <h2>Tham gia phòng đang có</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="join">
            <label class="bf-field">
                Mã phòng
                <input name="code" class="bf-input" value="<?= e($roomCode) ?>" required minlength="12" maxlength="12" placeholder="Mã gồm 12 ký tự">
            </label>
            <label class="bf-field">
                Mật khẩu (nếu có)
                <input name="password" type="password" class="bf-input" autocomplete="off" maxlength="100">
            </label>
            <button class="bf-btn">→ Tham gia phòng</button>
        </form>
        <h2 style="margin-top:28px">Phòng đã tham gia</h2>
        <?php foreach ($myRooms as $r): ?>
            <a href="?room=<?= e($r['code']) ?>" class="bf-row">
                <div>
                    <strong><?= e($r['title']) ?></strong>
                    <small><?= e($r['code']) ?></small>
                </div>
                <span>→</span>
            </a>
        <?php endforeach; ?>
        <?php if (!$myRooms): ?>
            <p class="bf-subtle">Bạn chưa tham gia phòng họp nào đang mở.</p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<?php bf_portal_end(); ?>