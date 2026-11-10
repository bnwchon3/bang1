/* Role guidance appears on the original login page AFTER successful sign-in.
   Nothing else in the existing Apps Script page design is changed. */
(function () {
 'use strict';
 const policies={
  Admin:{
   pages:['login','runners','register','print','schedule','results','score-summary','checkin','admin'],
   name:'Admin',
   permission:'จัดการและตั้งค่าระบบได้ทุกส่วน รวมถึงโรงเรียน ผู้ฝึกซ้อม การตรวจสอบนักวิ่ง และรายการแข่งขัน',
   steps:[
    {icon:'⚙️',title:'ตั้งค่าระบบ',detail:'ตรวจสอบเวลาเปิด–ปิด และบัญชีผู้ใช้งาน',page:'admin'},
    {icon:'🏫',title:'จัดการข้อมูล',detail:'ตรวจสอบโรงเรียน ผู้ฝึกซ้อม และข้อมูลนักกรีฑา',page:'admin'},
    {icon:'✅',title:'ตรวจสอบและติดตาม',detail:'อนุมัตินักวิ่งและติดตามการสมัครแข่งขัน',page:'admin'}
   ]
  },
  Manager:{
   pages:['login','checkin','admin'],
   name:'Manager',
   permission:'ตรวจสอบนักกรีฑา เลือกรายการแข่งขันที่กำลังแสดงบนจอ Live และติ๊กชื่อผู้ที่มารายงานตัวเป็นรายบุคคล',
   steps:[
    {icon:'🏟️',title:'เลือกรายการแข่งขัน',detail:'เปิดเมนูเช็กชื่อรายงานตัว แล้วเลือกรายการและฮีท',page:'checkin'},
    {icon:'📺',title:'เปิดรายการ Live',detail:'กดแสดงรายการนี้บนจอ Live',page:'checkin'},
    {icon:'✅',title:'ติ๊กชื่อนักกรีฑา',detail:'เลือกชื่อผู้ที่มารายงานตัว รูปจะขึ้นจอตามโรงเรียน',page:'checkin'}
   ]
  },
  ManagerRestricted:{
   pages:['login','admin'],
   name:'Manager',
   permission:'ตรวจสอบนักวิ่งได้ แต่ยังไม่ได้รับสิทธิ์เช็กชื่อรายงานตัวจาก Admin',
   steps:[
    {icon:'🔍',title:'ตรวจสอบนักวิ่ง',detail:'เปิดเมนูตรวจสอบนักวิ่งเพื่อปฏิบัติงานตามสิทธิ์ Manager',page:'admin'}
   ]
  },
  Staff:{
   pages:['login','results','score-summary'],
   name:'Staff',
   permission:'บันทึกและแก้ไขผลการแข่งขันเฉพาะรายการที่โรงเรียนสมัครไว้ โดยไม่สามารถแก้ข้อมูลนักกรีฑา ใบสมัคร หรือการตั้งค่าระบบ',
   steps:[
    {icon:'🏅',title:'เลือกรายการ',detail:'เปิดเมนูบันทึกผลการแข่งขัน เลือกรายการและฮีท',page:'results'},
    {icon:'🥇',title:'ระบุอันดับ',detail:'เลือกโรงเรียนในอันดับ 1–10 โดยไม่ซ้ำกัน',page:'results'},
    {icon:'✅',title:'บันทึกผล',detail:'ตรวจสอบและบันทึกผลลงระบบ',page:'results'}
   ]
  },
  Teacher:{
   pages:['login','runners','register','print'],
   name:'Teacher',
   permission:'🧾 2) ลงทะเบียนนักกรีฑา  •  ✅ 3) สมัครรายการกรีฑา  •  🖨️ 4) พิมพ์ใบสมัครกรีฑา',
   steps:[
    {icon:'🧾',title:'ลงทะเบียนนักกรีฑา',detail:'เพิ่มและตรวจข้อมูลนักกรีฑาของโรงเรียน',page:'runners'},
    {icon:'✅',title:'สมัครรายการกรีฑา',detail:'เลือกรายการแข่งขันและนักกรีฑา',page:'register'},
    {icon:'🖨️',title:'พิมพ์ใบสมัครกรีฑา',detail:'ตรวจสอบใบสมัครแล้วพิมพ์เอกสาร',page:'print'}
   ]
  }
 };
 function user(){return typeof currentUser!=='undefined'?currentUser:null;}
 function normRole(role){return role==='TopAdmin'?'Admin':role;}
 function policyFor(u){
   if(!u)return null;
   if(normRole(u.role)==='Manager'&&u.canLiveCheckin!==true)
     return policies.ManagerRestricted;
   return policies[normRole(u.role)]||null;
 }
 function can(page,u){return !!(page==='login'||page==='score-summary'||(policyFor(u)&&policyFor(u).pages.includes(page)));}
 function escapeHTML(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));}
 function ensureDashboard(){
   const page=document.getElementById('page-login');if(!page)return null;
   let board=document.getElementById('bang1-login-guidance');
   if(!board){
    board=document.createElement('div');
    board.id='bang1-login-guidance';
    board.className='card';
    board.hidden=true;
    page.appendChild(board);
   }
   return board;
 }
 function apply(){
   const u=user(),p=policyFor(u);
   document.querySelectorAll('.menu button[data-page]').forEach(b=>{
     const page=b.dataset.page;
     const show=can(page,u);
     b.hidden=!show;
     b.style.display=show?'':'none';
     if(page==='results'){
       b.innerHTML=(u&&u.role==='Staff')
         ? '<span class="icon">🏅</span> บันทึกผลการแข่งขัน'
         : '<span class="icon">🏅</span> ผลการแข่งขัน';
     }
     if(page==='admin'){
       const label=b.querySelector('.role-menu-label');
       if(label)label.remove();
       if(u&&normRole(u.role)==='Manager'){
          const span=document.createElement('span');
          span.className='role-menu-label';
          span.textContent=' ตรวจสอบนักวิ่ง';
          b.childNodes.forEach(n=>{if(n.nodeType===Node.TEXT_NODE)n.textContent=''});
          b.appendChild(span);
       }else{
          // Restore the pre-existing menu text exactly.
          b.innerHTML='<span class="icon">🛠️</span> ผู้ดูแลระบบ';
       }
     }
   });
   const tabs=document.getElementById('admin-sheet-tabs');
   if(tabs){
     tabs.querySelectorAll('[data-admin-tab]').forEach(b=>{
       const show=!!p&&(normRole(u.role)==='Admin'||b.dataset.adminTab==='check');
       b.hidden=!show;
       b.style.display=show?'':'none';
     });
     if(u&&normRole(u.role)==='Manager'){
       tabs.querySelector('[data-admin-tab="check"]')?.click();
     }
   }
   const logoutButton=document.getElementById('bang1LogoutButton');
   if(logoutButton){
     logoutButton.hidden=!u;
     logoutButton.style.display=u?'':'none';
   }
   const dashboard=ensureDashboard();
   const form=document.querySelector('#page-login > .card:not(#bang1-login-guidance)');
   if(form)form.hidden=!!p;
   // Once a user is authenticated, the public member directory no longer
   // belongs on their landing page; keep the role summary intact.
   const memberDirectory=document.getElementById('bang1AccountPortal');
   if(memberDirectory)memberDirectory.hidden=!!u;
   if(!dashboard)return;
   dashboard.hidden=!p;
   if(!p){dashboard.replaceChildren();return}
   dashboard.innerHTML=
    '<div class="role-guidance-header"><div><div class="role-guidance-overline">เข้าสู่ระบบเรียบร้อย</div>'+
    '<h2>สิทธิ์การใช้งาน: '+escapeHTML(p.name)+'</h2></div>'+
    '<div class="role-guidance-role">'+escapeHTML(p.name)+'</div></div>'+
    '<div class="role-guidance-subtitle"><strong>สิทธิ์ของคุณ</strong><p>'+escapeHTML(p.permission)+'</p></div>'+
    '<h3 class="role-guidance-title">ลำดับการทำงาน 1 → 2 → 3</h3>'+
    '<div class="role-guidance-steps">'+p.steps.map((s,i)=>
      '<button type="button" class="role-guidance-step" data-role-go="'+escapeHTML(s.page)+'">'+
      '<div class="role-guidance-step-top"><span class="role-guidance-number">'+(i+1)+'</span>'+
      '<span class="role-guidance-icon" aria-hidden="true">'+s.icon+'</span></div>'+
      '<strong>'+escapeHTML(s.title)+'</strong><small>'+escapeHTML(s.detail)+'</small></button>').join('')+
    '</div>';
   dashboard.querySelectorAll('[data-role-go]').forEach(b=>b.addEventListener('click',()=>{
     if(typeof navigate==='function')navigate(b.dataset.roleGo);
   }));
 }
 window.bang1Role={can,apply};
 window.applyBang1Permissions=apply;
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',apply);
 else apply();
})();