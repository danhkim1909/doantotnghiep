/* Universal navigation / quick-jump layer. No API or database changes. */
(()=>{'use strict';const init=()=>{
  const side=document.querySelector('aside.sidebar,aside.bf-rail');
  const top=document.querySelector('.topbar,.bf-top');
  if(!side||!top)return;
  const nav=side.querySelector('nav');if(!nav)return;
  const bar=top.firstElementChild||top;
  const tray=document.createElement('div');tray.className='bfux-toolbar';
  const icoSearch='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>';
  const icoMenu='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';
  const search=document.createElement('button');search.type='button';search.className='bfux-tool';search.innerHTML=icoSearch+' Tìm chức năng <span class="bfux-kbd">Ctrl K</span>';search.setAttribute('aria-label','Tìm chức năng trong menu');
  const toggle=document.createElement('button');toggle.type='button';toggle.className='bfux-tool bfux-sidebar-toggle';if(side.classList.contains('bf-rail'))toggle.hidden=true;toggle.innerHTML=icoMenu+' Thu gọn';toggle.setAttribute('aria-label','Thu gọn hoặc mở rộng thanh điều hướng');
  tray.append(toggle,search);bar.appendChild(tray);
  const lsKey='bfux_sidebar_collapsed';const narrow=()=>window.matchMedia('(max-width:1100px)').matches;
  try{if(!narrow()&&localStorage.getItem(lsKey)==='1')document.body.classList.add('bfux-collapsed')}catch(_e){}
  function sync(){const v=document.body.classList.contains('bfux-collapsed');toggle.innerHTML=icoMenu+(v?' Mở menu':' Thu gọn');toggle.setAttribute('aria-expanded',String(!v))}
  sync();toggle.addEventListener('click',()=>{if(narrow())return;document.body.classList.toggle('bfux-collapsed');try{localStorage.setItem(lsKey,document.body.classList.contains('bfux-collapsed')?'1':'0')}catch(_e){}sync()});
  const layer=document.createElement('div');layer.className='bfux-search-layer';layer.hidden=true;layer.setAttribute('role','presentation');
  const dialog=document.createElement('section');dialog.className='bfux-search-box';dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label','Tìm nhanh chức năng');
  const heading=document.createElement('div');heading.className='bfux-search-heading';heading.textContent='Đi tới chức năng';
  const close=document.createElement('button');close.type='button';close.className='bfux-search-close';close.textContent='×';close.setAttribute('aria-label','Đóng tìm kiếm');heading.appendChild(close);
  const input=document.createElement('input');input.type='search';input.className='bfux-search-input';input.placeholder='Nhập tên chức năng, ví dụ: Khóa học, Chấm bài…';input.setAttribute('aria-label','Tìm chức năng');
  const results=document.createElement('div');results.className='bfux-search-results';const help=document.createElement('div');help.className='bfux-search-help';help.textContent='↑ ↓ để chọn · Enter để mở · Esc để đóng';
  dialog.append(heading,input,results,help);layer.appendChild(dialog);document.body.appendChild(layer);
  const links=[...nav.querySelectorAll('a[href]')].filter(a=>{const label=(a.textContent||'').trim();return label.length>1&&a.getAttribute('href')!=='#'}).map(a=>({label:(a.textContent||'').replace(/\s+/g,' ').trim(),href:a.href}));
  const unique=links.filter((x,i)=>links.findIndex(y=>y.href===x.href)===i);let visible=[];let selection=0;let focusBefore=null;
  function normalize(s){return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d').replace(/Đ/g,'D').toLowerCase()}
  function render(){const q=normalize(input.value.trim());visible=unique.filter(x=>normalize(x.label).includes(q)).slice(0,14);selection=0;results.replaceChildren();if(!visible.length){const msg=document.createElement('div');msg.className='bfux-no-results';msg.textContent='Không tìm thấy chức năng phù hợp.';results.appendChild(msg);return}visible.forEach((item,i)=>{const a=document.createElement('a');a.href=item.href;const dot=document.createElement('span');dot.className='bfux-search-icon';dot.textContent='↗';const label=document.createElement('span');label.textContent=item.label;a.append(dot,label);if(i===selection)a.style.background='#eef2ff';results.appendChild(a)});}
  function open(){focusBefore=document.activeElement;layer.hidden=false;document.body.classList.add('bfux-modal-active');input.value='';render();input.focus()}
  function hide(){layer.hidden=true;document.body.classList.remove('bfux-modal-active');if(focusBefore&&typeof focusBefore.focus==='function')focusBefore.focus()}
  search.addEventListener('click',open);close.addEventListener('click',hide);layer.addEventListener('click',e=>{if(e.target===layer)hide()});input.addEventListener('input',render);
  document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();layer.hidden?open():hide();return}if(layer.hidden)return;if(e.key==='Escape'){e.preventDefault();hide()}else if(e.key==='Tab'){const els=[close,input,...results.querySelectorAll('a')];const first=els[0],last=els[els.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}else if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(!visible.length)return;selection=(selection+(e.key==='ArrowDown'?1:-1)+visible.length)%visible.length;[...results.querySelectorAll('a')].forEach((a,i)=>a.style.background=i===selection?'#eef2ff':'');}else if(e.key==='Enter'&&document.activeElement===input&&visible[selection]){e.preventDefault();window.location.assign(visible[selection].href)}});
  window.addEventListener('resize',()=>{if(narrow())document.body.classList.remove('bfux-collapsed');else{try{if(localStorage.getItem(lsKey)==='1')document.body.classList.add('bfux-collapsed')}catch(_e){}}sync()});
};document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init()})();
