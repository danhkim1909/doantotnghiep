/* BrightFuture small-group WebRTC mesh. No server-side media storage. */
(()=>{'use strict';
const cfg=window.BF_ROOM_CONFIG;if(!cfg)return;
const by=id=>document.getElementById(id),status=by('callStatus'),grid=by('videoGrid'),log=by('roomMessages');
const peers=new Map(),pending=new Map();let stream=null,screenStream=null,lastSignal=0,lastChat=0,requesting=false,polling=false,stopped=false;
const me=Number(cfg.uid),room=Number(cfg.id),MAX_PEERS=5;
const roomLink=location.origin+cfg.link;
by('copyRoom').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(roomLink);by('copyRoom').textContent='Đã sao chép';}catch(e){window.prompt('Sao chép liên kết phòng:',roomLink);}});
const setStatus=s=>status.textContent=s;
async function post(action,fields={}){
 const params=new URLSearchParams({...fields,action,room,csrf_token:cfg.csrf});
 const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:params,headers:{Accept:'application/json'}});
 if(!response.ok)throw Error('Máy chủ từ chối thao tác ('+response.status+')');
 const result=await response.json();if(!result.ok)throw Error(result.message||'Không xử lý được yêu cầu');return result;
}
const remoteTile=(id,name)=>{
 let tile=by('remote-'+id);if(tile)return tile;
 tile=document.createElement('div');tile.className='bf-video-wrap';tile.id='remote-'+id;
 const label=document.createElement('label');label.textContent=name||'Thành viên '+id;
 const media=document.createElement('video');media.autoplay=true;media.playsInline=true;
 const placeholder=document.createElement('div');placeholder.className='bf-placeholder';placeholder.textContent='Đang kết nối...';
 tile.append(placeholder,media,label);grid.append(tile);return tile;
};
const removePeer=id=>{let item=peers.get(id);if(item){item.pc.onicecandidate=null;item.pc.ontrack=null;item.pc.close();peers.delete(id)}let tile=by('remote-'+id);if(tile)tile.remove();pending.delete(id)};
const sendSignal=async(to,kind,data)=>{try{await post('signal',{to,kind,payload:JSON.stringify(data)})}catch(e){setStatus(e.message)}};
function getPeer(id,name){
 id=Number(id);if(peers.has(id))return peers.get(id);
 const pc=new RTCPeerConnection({iceServers:[{urls:['stun:stun.l.google.com:19302']}]});
 const item={pc,name,offered:false};peers.set(id,item);
 pc.onicecandidate=event=>{if(event.candidate)void sendSignal(id,'candidate',event.candidate.toJSON())};
 pc.ontrack=event=>{const tile=remoteTile(id,name),video=tile.querySelector('video');let media=event.streams[0];if(!media){media=video.srcObject||new MediaStream();media.addTrack(event.track)}video.srcObject=media;video.play().catch(()=>{});};
 pc.onconnectionstatechange=()=>{if(pc.connectionState==='failed')setStatus('Một kết nối gặp lỗi mạng. Bạn có thể tải lại trang để thử lại.');};
 if(stream)for(const track of stream.getTracks())pc.addTrack(track,stream);
 const queue=pending.get(id);if(queue?.length)pending.delete(id);
 return item;
}
async function offer(id,name,force=false){
 const item=getPeer(id,name);if(item.offered&&!force)return;
 if(item.pc.signalingState!=='stable')return;
 try{item.offered=true;const description=await item.pc.createOffer();await item.pc.setLocalDescription(description);await sendSignal(id,'offer',item.pc.localDescription.toJSON());}catch(e){setStatus('Không thể tạo kết nối: '+e.message)}
}
async function signalOne(m){
 const id=Number(m.sender_id);if(id===me)return;
 let payload;try{payload=JSON.parse(m.payload)}catch(e){return}
 const item=getPeer(id,'Thành viên '+id),pc=item.pc;
 try{
  if(m.kind==='offer'){
   if(pc.signalingState!=='stable'){await pc.setLocalDescription({type:'rollback'})}
   await pc.setRemoteDescription(new RTCSessionDescription(payload));
   const answer=await pc.createAnswer();await pc.setLocalDescription(answer);await sendSignal(id,'answer',pc.localDescription.toJSON());
  }else if(m.kind==='answer'){
   if(pc.signalingState==='have-local-offer')await pc.setRemoteDescription(new RTCSessionDescription(payload));
  }else if(m.kind==='candidate'){
   if(!pc.remoteDescription){if(!pending.has(id))pending.set(id,[]);pending.get(id).push(payload);return}
   await pc.addIceCandidate(new RTCIceCandidate(payload));
  }
  if(pc.remoteDescription&&pending.has(id)){for(const candidate of pending.get(id)){await pc.addIceCandidate(new RTCIceCandidate(candidate))}pending.delete(id)}
 }catch(e){setStatus('Sự cố khi thiết lập cuộc gọi: '+e.message)}
}
async function refresh(){
 if(stopped||polling)return;polling=true;
 try{
  const params=new URLSearchParams({room,after:lastSignal,chat_after:lastChat});
  const r=await fetch(cfg.endpoint+'?'+params,{credentials:'same-origin',headers:{Accept:'application/json'}});
  if(r.status===410||r.status===403){stopped=true;setStatus('Phòng đã kết thúc hoặc bạn không còn trong phòng.');return}
  if(!r.ok)throw Error('Không thể kết nối máy chủ.');
  const data=await r.json();if(!data.ok)throw Error('Lỗi tải dữ liệu phòng.');
  const active=new Set((data.participants||[]).map(p=>Number(p.id)));
  const names=(data.participants||[]).map(p=>p.full_name);by('participantsText').textContent=names.join(' · ')||'Chưa có người tham gia';
  for(const [id] of peers)if(!active.has(id))removePeer(id);
  for(const s of data.signals||[]){lastSignal=Math.max(lastSignal,Number(s.id));await signalOne(s)}
  for(const c of data.messages||[]){lastChat=Math.max(lastChat,Number(c.id));const p=document.createElement('p');const small=document.createElement('small');small.textContent=c.sender+' · '+c.created_at;p.append(small,document.createTextNode(c.body));log.append(p);while(log.children.length>100)log.firstElementChild.remove();log.scrollTop=log.scrollHeight}
  if(stream){for(const p of data.participants||[]){const id=Number(p.id);if(id!==me&&!peers.has(id)&&peers.size<MAX_PEERS){getPeer(id,p.full_name);if(me<id)await offer(id,p.full_name)}}}
 }catch(e){setStatus(e.message)}finally{polling=false}
}
by('startCall').addEventListener('click',async()=>{
 if(requesting)return;requesting=true;
 try{
  if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;for(const [id] of peers)removePeer(id);by('localVideo').srcObject=null;by('startCall').textContent='Bật camera / micro';by('toggleMic').disabled=true;by('toggleCamera').disabled=true;by('shareScreen').disabled=true;setStatus('Đã tắt camera và micro.');return}
  if(!navigator.mediaDevices?.getUserMedia)throw Error('Trình duyệt yêu cầu HTTPS hoặc localhost để truy cập camera/micro.');
  stream=await navigator.mediaDevices.getUserMedia({audio:true,video:true}).catch(async()=>navigator.mediaDevices.getUserMedia({audio:true,video:false}));
  by('localVideo').srcObject=stream;await by('localVideo').play().catch(()=>{});
  by('startCall').textContent='Tắt kết nối thiết bị';by('toggleMic').disabled=!stream.getAudioTracks().length;by('toggleCamera').disabled=!stream.getVideoTracks().length;by('shareScreen').disabled=!stream.getVideoTracks().length;
  by('toggleMic').textContent='Tắt micro';by('toggleCamera').textContent='Tắt camera';setStatus('Đã kết nối thiết bị. Đang tìm thành viên...');
  // Start negotiation with peers who may have joined before the camera was opened.
  for(const [id] of peers)removePeer(id);await refresh();
 }catch(e){setStatus('Không thể bật thiết bị: '+e.message+' (hãy kiểm tra quyền camera/micro).')}finally{requesting=false}
});
by('toggleMic').addEventListener('click',()=>{if(!stream)return;const a=stream.getAudioTracks()[0];if(!a)return;a.enabled=!a.enabled;by('toggleMic').textContent=a.enabled?'Tắt micro':'Bật micro'});
by('toggleCamera').addEventListener('click',()=>{if(!stream)return;const v=stream.getVideoTracks()[0];if(!v)return;v.enabled=!v.enabled;by('toggleCamera').textContent=v.enabled?'Tắt camera':'Bật camera'});
by('shareScreen').addEventListener('click',async()=>{
 if(!stream)return;
 try{
  if(screenStream){screenStream.getTracks().forEach(t=>t.stop());screenStream=null;by('shareScreen').textContent='Chia sẻ màn hình';return}
  screenStream=await navigator.mediaDevices.getDisplayMedia({video:true});const track=screenStream.getVideoTracks()[0];const original=stream.getVideoTracks()[0];
  async function restore(){for(const {pc} of peers.values()){const sender=pc.getSenders().find(s=>s.track?.kind==='video');if(sender)await sender.replaceTrack(original||null)}by('localVideo').srcObject=stream;by('shareScreen').textContent='Chia sẻ màn hình';screenStream=null}
  for(const {pc} of peers.values()){const sender=pc.getSenders().find(s=>s.track?.kind==='video');if(sender)await sender.replaceTrack(track)}by('localVideo').srcObject=screenStream;track.addEventListener('ended',()=>{void restore()},{once:true});by('shareScreen').textContent='Dừng chia sẻ';
 }catch(e){setStatus('Không chia sẻ được màn hình: '+e.message)}
});
by('roomMessageForm').addEventListener('submit',async event=>{event.preventDefault();const input=by('roomMessageInput'),body=input.value.trim();if(!body)return;try{await post('chat',{body});input.value='';await refresh()}catch(e){setStatus(e.message)}});
setStatus('Phòng đã mở. Bấm “Bật camera / micro” để gọi.');setInterval(refresh,1700);void refresh();
})();
