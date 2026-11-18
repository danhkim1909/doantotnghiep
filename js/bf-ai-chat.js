(()=>{'use strict';
const config=window.BF_AI_CONFIG||{};
const form=document.getElementById('aiForm'),input=document.getElementById('aiInput');
const send=document.getElementById('aiSend'),messages=document.getElementById('aiMessages');
const clear=document.getElementById('aiReset'),error=document.getElementById('aiError');
if(!form||!input||!send||!messages||!clear)return;
let busy=false;
function showError(text){error.textContent=text||'Không gửi được câu hỏi. Vui lòng thử lại.';error.hidden=false;}
function addMessage(role,text,pending=false){
 const blank=document.getElementById('aiEmpty');if(blank)blank.remove();
 const wrap=document.createElement('div');wrap.className='ai-message '+(role==='user'?'ai-user':'ai-bot')+(pending?' ai-pending':'');
 const bubble=document.createElement('div');bubble.className='ai-bubble';
 const name=document.createElement('span');name.className='ai-name';name.textContent=role==='user'?'Bạn':'Trợ giảng AI';
 const content=document.createElement('div');content.className='ai-text';content.textContent=text;
 bubble.append(name,content);wrap.append(bubble);messages.append(wrap);messages.scrollTop=messages.scrollHeight;
 return wrap;
}
function setBusy(state){busy=state;send.disabled=state||input.disabled;clear.disabled=state;input.readOnly=state;send.textContent=state?'Đang trả lời…':'Gửi ➤';}
async function request(data){const res=await fetch(config.api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':config.csrf},body:JSON.stringify({...data,lesson_id:Number(config.lessonId)||0})});
 let json;try{json=await res.json();}catch(e){throw new Error('Không đọc được phản hồi từ máy chủ. Có thể phiên đăng nhập đã hết hạn.');}
 if(!res.ok||!json.ok)throw new Error(json.message||'Không kết nối được chatbot.');return json;}
async function submit(){const value=input.value.trim();if(busy||!value)return;
 if(new TextEncoder().encode(value).length>7000){showError('Câu hỏi quá dài. Hãy rút gọn rồi gửi lại.');return;}
 error.hidden=true;const userMessage=addMessage('user',value);input.value='';setBusy(true);
 const pending=addMessage('assistant','Đang suy nghĩ câu trả lời…',true);
 try{const res=await request({action:'send',message:value});pending.querySelector('.ai-text').textContent=res.answer;pending.classList.remove('ai-pending');}
 catch(e){pending.remove();userMessage.remove();showError(e.message||'Không thể gửi câu hỏi.');input.value=value;}
 finally{setBusy(false);input.focus();messages.scrollTop=messages.scrollHeight;}
}
form.addEventListener('submit',e=>{e.preventDefault();void submit();});
input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();void submit();}});
clear.addEventListener('click',async()=>{if(busy)return;error.hidden=true;setBusy(true);try{await request({action:'reset'});messages.replaceChildren();const div=document.createElement('div');div.className='ai-empty';div.id='aiEmpty';div.innerHTML='<div class="ai-hero-symbol">✧</div><h2>Cuộc trò chuyện mới</h2><p>Bạn có thể hỏi một câu hỏi mới bên dưới.</p>';messages.append(div);input.value='';}catch(e){showError(e.message);}finally{setBusy(false);input.focus();}});
document.querySelectorAll('[data-prompt]').forEach(button=>button.addEventListener('click',()=>{input.value=button.dataset.prompt||'';input.focus();}));
messages.scrollTop=messages.scrollHeight;
})();
