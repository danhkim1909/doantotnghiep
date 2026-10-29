/* Add missing navigation to existing role-specific sidebars without replacing their business logic. */
(()=>{'use strict';const config=window.BF_LEGACY;if(!config)return;
const base=String(config.base||'').replace(/\/$/,''),role=config.role;
const sidebar=document.querySelector('aside.sidebar,aside.side');if(!sidebar)return;
const nav=sidebar.querySelector('nav.sidebar-nav,nav.nav');if(!nav)return;
const common=[['social.php','♧','Bạn bè & tin nhắn'],['rooms.php','◉','Phòng gọi nhóm']];
if(role==='admin')common.push(['admin/moderation.php','⚑','Kiểm duyệt cộng đồng']);
const heading=document.createElement('div');heading.className='bf-nav-section';heading.textContent='CỘNG ĐỒNG';nav.appendChild(heading);
for(const [path,icon,label] of common){const a=document.createElement('a');a.className='bf-extra-link';a.href=base+'/'+path;const ico=document.createElement('span');ico.className='bf-icon';ico.textContent=icon;const text=document.createElement('span');text.textContent=label;a.append(ico,text);if(location.pathname.replace(/\/$/,'').toLowerCase()===a.pathname.replace(/\/$/,'').toLowerCase()){a.classList.add('active');a.setAttribute('aria-current','page')}nav.appendChild(a)}
// Ensure existing active link follows the current URL even when some legacy pages reuse templates.
const current=location.pathname.replace(/\/$/,'').toLowerCase();
for(const a of nav.querySelectorAll('a[href]')){try{if(new URL(a.href).pathname.toLowerCase()===current){a.classList.add('active');a.setAttribute('aria-current','page')}}catch(e){}}
})();
