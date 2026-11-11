/* Public account directory and secure email-OTP account recovery. */
(function(){
 'use strict';
 var token='',people=[],schools=[],filter='',resetCoachId=0,busy=false;
 function byId(id){return document.getElementById(id)}
 function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
 async function publicApi(action,data){
   var isWrite=data!==undefined;
   var response=await fetch('api.php?action='+encodeURIComponent(action),{
     method:isWrite?'POST':'GET',credentials:'same-origin',
     headers:isWrite?{'Content-Type':'application/json','X-CSRF-Token':token}:{},
     body:isWrite?JSON.stringify(data):undefined
   });
   var payload;
   try{payload=await response.json()}catch(e){throw Error('ไม่สามารถอ่านข้อมูลจากเซิร์ฟเวอร์')}
   if(!response.ok||payload.success===false)throw Error(payload.message||'ทำรายการไม่สำเร็จ');
   return payload;
 }
 function notice(text,error){
   var n=byId('bang1AccountDialogMessage');
   if(n){n.textContent=text||'';n.className='bang1-account-message'+(error?' is-error':'');}
 }
 function status(text,error){
   var n=byId('bang1AccountStatus');
   if(n){n.textContent=text||'';n.className='bang1-account-status'+(error?' is-error':'');}
 }
 function build(){
   var login=byId('page-login');if(!login)return;
   var portal=document.createElement('section');
   portal.id='bang1AccountPortal';
   portal.className='card bang1-account-card';
   // Session restoration can finish before this script initializes.
   // Match the authenticated state at creation time to avoid a roster flash.
   portal.hidden=typeof currentUser!=='undefined'&&!!currentUser;
   portal.innerHTML=
     '<div class="bang1-account-heading"><div><h2>รายชื่อผู้มีสิทธิเข้าใช้งาน</h2>'+
     '<p>ตรวจสอบชื่อ โรงเรียน และอีเมลที่ลงทะเบียน โดยระบบปกปิดอีเมลบางส่วนเพื่อความปลอดภัย</p></div>'+
     '<button class="btn btn-primary" type="button" id="bang1SignupOpen">+ สมัครใหม่</button></div>'+
     '<div class="bang1-account-toolbar"><label for="bang1AccountSearch">ค้นหารายชื่อหรือโรงเรียน</label>'+
     '<input id="bang1AccountSearch" type="search" placeholder="ค้นหาชื่อ โรงเรียน หรืออีเมลที่ปกปิด"></div>'+
     '<div class="bang1-account-table-scroll"><table class="bang1-account-table"><thead><tr>'+
     '<th>ชื่อ - นามสกุล</th><th>โรงเรียน</th><th>อีเมลที่ลงทะเบียน</th><th>จัดการบัญชี</th>'+
     '</tr></thead><tbody id="bang1AccountsRows"><tr><td colspan="4">กำลังโหลดรายชื่อ...</td></tr></tbody></table></div>'+
     '<p id="bang1AccountStatus" class="bang1-account-status" role="status" aria-live="polite"></p>';
   var loginForm=login.querySelector('.card');
   if(login.querySelector('#bang1DonateGalaxy'))login.querySelector('#bang1DonateGalaxy').insertAdjacentElement('afterend',portal);
   else if(loginForm)loginForm.insertAdjacentElement('afterend',portal);
   else login.appendChild(portal);
   var dialog=document.createElement('dialog');
   dialog.id='bang1AccountDialog';
   dialog.className='bang1-account-dialog';
   dialog.innerHTML='<div class="bang1-account-dialog-head"><h3 id="bang1AccountDialogTitle"></h3>'+
     '<button type="button" class="bang1-account-close" id="bang1AccountDialogClose" aria-label="ปิดหน้าต่าง">×</button></div>'+
     '<div id="bang1AccountDialogBody"></div><p class="bang1-account-message" id="bang1AccountDialogMessage" role="status" aria-live="polite"></p>';
   document.body.appendChild(dialog);
   byId('bang1AccountDialogClose').onclick=function(){dialog.close()};
   dialog.addEventListener('close',function(){byId('bang1AccountDialogBody').replaceChildren();resetCoachId=0});
   byId('bang1SignupOpen').onclick=openSignup;
   byId('bang1AccountSearch').addEventListener('input',function(e){filter=e.target.value.toLowerCase().trim();renderPeople()});
   byId('bang1AccountsRows').addEventListener('click',function(e){
     var button=e.target.closest('button[data-coach]');
     if(button)openReset(Number(button.dataset.coach));
   });
   buildAdminApplications();
   loadPeople();
 }
 async function loadPeople(){
   try{
     var r=await publicApi('account_portal');
     token=r.csrf||'';
     people=Array.isArray(r.data)?r.data:[];
     schools=Array.isArray(r.schools)?r.schools:[];
     renderPeople();status('แสดงรายชื่อผู้มีสิทธิใช้งาน '+people.length+' รายการ');
   }catch(e){
     status(e.message,true);
     byId('bang1AccountsRows').innerHTML='<tr><td colspan="4">โหลดรายชื่อไม่สำเร็จ กรุณารีเฟรชหน้าเว็บ</td></tr>';
   }
 }
 function renderPeople(){
   var list=people.filter(function(p){
     return !filter||[p.name,p.school,p.emailMasked].some(function(x){return String(x||'').toLowerCase().includes(filter)});
   });
   byId('bang1AccountsRows').innerHTML=list.length?list.map(function(p){
     return '<tr><td class="bang1-account-name">'+esc(p.name)+'</td>'+
       '<td>'+esc(p.school)+'</td><td class="bang1-account-masked">'+esc(p.emailMasked)+'</td>'+
       '<td><button type="button" class="bang1-account-reset" data-coach="'+Number(p.id)+'">รีเซ็ตรหัสผ่าน</button></td></tr>';
   }).join(''):'<tr><td colspan="4">ไม่พบรายชื่อที่ค้นหา</td></tr>';
 }
 function openDialog(title,markup){
   byId('bang1AccountDialogTitle').textContent=title;
   byId('bang1AccountDialogBody').innerHTML=markup;
   notice('');
   var d=byId('bang1AccountDialog');
   if(!d.open)d.showModal();
 }
 function submit(form,work){
   form.addEventListener('submit',async function(e){
     e.preventDefault();if(busy||!form.reportValidity())return;
     var btn=form.querySelector('button[type="submit"]');busy=true;
     if(btn)btn.disabled=true;
     notice('กำลังดำเนินการ...');
     try{await work(new FormData(form))}
     catch(err){notice(err.message||'ไม่สามารถทำรายการได้',true)}
     finally{busy=false;if(btn && btn.isConnected)btn.disabled=false}
   });
 }
 function openReset(id){
   var person=people.find(function(x){return Number(x.id)===id});
   if(!person)return;
   resetCoachId=id;
   openDialog('รีเซ็ตรหัสผ่าน',
     '<p>บัญชี: <strong>'+esc(person.name)+'</strong><br>'+esc(person.school)+'</p>'+
     '<p>กรอกอีเมลที่ลงทะเบียนให้ครบถ้วน ระบบจะส่งรหัสยืนยันไปยังเจ้าของอีเมล</p>'+
     '<form id="bang1ResetEmailForm"><label>อีเมลที่ลงทะเบียน<input type="email" name="email" required autocomplete="email" placeholder="'+esc(person.emailMasked)+'"></label>'+
     '<button class="btn btn-primary" type="submit">ส่งรหัสยืนยันทางอีเมล</button></form>');
   submit(byId('bang1ResetEmailForm'),async function(values){
     var r=await publicApi('account_reset_request',{id:resetCoachId,email:values.get('email')});
     if(r.requiresCode){showCodeForm();notice(r.message)}
     else notice(r.message||'หากข้อมูลถูกต้อง จะมีการส่งรหัสยืนยัน');
   });
 }
 function showCodeForm(){
   byId('bang1AccountDialogBody').innerHTML=
     '<div class="bang1-account-alert">กรุณาตรวจสอบกล่องจดหมายและโฟลเดอร์สแปม รหัสมีอายุ 10 นาที</div>'+
     '<form id="bang1ResetCodeForm"><label>รหัสยืนยัน 6 หลักจากอีเมล'+
     '<input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000" required></label>'+
     '<button type="submit" class="btn btn-primary">ตรวจสอบรหัสยืนยัน</button></form>';
   submit(byId('bang1ResetCodeForm'),async function(values){
     var r=await publicApi('account_reset_verify',{code:values.get('code')});
     if(r.verified)showNewPasswordForm();
   });
 }
 function showNewPasswordForm(){
   byId('bang1AccountDialogBody').innerHTML=
     '<div class="bang1-account-verified">✓ ยืนยันเจ้าของอีเมลสำเร็จ</div>'+
     '<form id="bang1ResetPasswordForm"><label>รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)'+
     '<input type="password" name="password" minlength="8" maxlength="128" autocomplete="new-password" required></label>'+
     '<label>ยืนยันรหัสผ่านใหม่'+
     '<input type="password" name="confirmPassword" minlength="8" maxlength="128" autocomplete="new-password" required></label>'+
     '<button type="submit" class="btn btn-primary">บันทึกรหัสผ่านใหม่</button></form>';
   notice('อีเมลได้รับการยืนยันแล้ว');
   submit(byId('bang1ResetPasswordForm'),async function(v){
     if(v.get('password')!==v.get('confirmPassword'))throw Error('รหัสผ่านทั้งสองช่องไม่ตรงกัน');
     var r=await publicApi('account_reset_complete',{password:v.get('password'),confirmPassword:v.get('confirmPassword')});
     byId('bang1AccountDialogBody').innerHTML='<div class="bang1-account-verified">✓ '+esc(r.message)+'</div>'+
       '<button class="btn btn-outline" type="button" id="bang1ResetDone">กลับไปเข้าสู่ระบบ</button>';
     byId('bang1ResetDone').onclick=function(){byId('bang1AccountDialog').close();byId('loginEmail').focus()};
     notice('');
   });
 }
 function openSignup(){
   openDialog('ขอสมัครเข้าใช้งานใหม่',
     '<p>กรอกข้อมูลจริงเพื่อส่งคำขอให้ Admin ตรวจสอบและอนุมัติ ก่อนเข้าสู่ระบบ</p>'+
     '<form id="bang1SignupForm"><div class="bang1-account-form-row">'+
     '<label>ชื่อจริง<input name="firstName" required maxlength="80" autocomplete="given-name"></label>'+
     '<label>นามสกุล<input name="lastName" required maxlength="80" autocomplete="family-name"></label></div>'+
     '<label>โรงเรียน<select name="school" required><option value="">เลือกโรงเรียน</option>'+
     schools.map(function(s){return '<option value="'+esc(s)+'">'+esc(s)+'</option>'}).join('')+'</select></label>'+
     '<label>อีเมลที่ติดต่อได้<input type="email" name="email" required maxlength="255" autocomplete="email" placeholder="name@school.ac.th"></label>'+
     '<button class="btn btn-primary" type="submit">ส่งคำขอสมัครให้ Admin</button></form>');
   submit(byId('bang1SignupForm'),async function(v){
     var r=await publicApi('account_signup',{firstName:v.get('firstName'),lastName:v.get('lastName'),
       school:v.get('school'),email:v.get('email')});
     byId('bang1AccountDialogBody').innerHTML='<div class="bang1-account-verified">✓ '+esc(r.message)+'</div>'+
       '<button type="button" class="btn btn-outline" id="bang1SignupDone">ปิด</button>';
     byId('bang1SignupDone').onclick=function(){byId('bang1AccountDialog').close()};
     notice('');
   });
 }
 function buildAdminApplications(){
   var tabs=byId('admin-sheet-tabs'),page=byId('page-admin');if(!tabs||!page)return;
   if(byId('admin-sheet-applications'))return;
   var tab=document.createElement('button');
   tab.type='button';tab.className='btn btn-outline btn-xs';
   tab.dataset.adminTab='applications';tab.setAttribute('role','tab');
   tab.setAttribute('aria-selected','false');tab.textContent='คำขอสมัครใหม่';
   var coaches=tabs.querySelector('[data-admin-tab="coaches"]');
   if(coaches)coaches.insertAdjacentElement('afterend',tab);else tabs.appendChild(tab);
   var panel=document.createElement('div');
   panel.id='admin-sheet-applications';
   panel.dataset.adminSheetPanel='applications';
   panel.hidden=true;
   panel.innerHTML='<div class="card"><div class="card-header"><div class="card-title">พิจารณาคำขอสมัครบัญชี</div></div>'+
     '<p>ผู้สมัครจะไม่ได้รับสิทธิใช้งานจนกว่า Admin จะอนุมัติ บัญชีที่อนุมัติใหม่จะได้รับสิทธิ์ Teacher</p>'+
     '<button type="button" class="btn btn-outline btn-xs" id="bang1ApplicationsRefresh">รีเฟรชรายการ</button>'+
     '<div class="bang1-account-table-scroll"><table class="bang1-account-table"><thead><tr><th>ชื่อ - นามสกุล</th><th>โรงเรียน</th><th>อีเมล</th><th>วันที่สมัคร</th><th>สถานะบัญชี</th><th>อีเมลแจ้งอนุมัติ</th><th>จัดการ</th></tr></thead>'+
     '<tbody id="bang1ApplicationsRows"><tr><td colspan="7">เลือกแท็บเพื่อโหลดข้อมูล</td></tr></tbody></table></div>'+
     '<p class="bang1-account-status" id="bang1ApplicationsStatus" role="status" aria-live="polite"></p></div>';
   page.appendChild(panel);
   tab.addEventListener('click',function(){loadApplications()});
   byId('bang1ApplicationsRefresh').onclick=loadApplications;
   byId('bang1ApplicationsRows').addEventListener('click',async function(e){
     var btn=e.target.closest('button[data-review],button[data-resend]');if(!btn)return;
     var id=Number(btn.dataset.id),resend=btn.hasAttribute('data-resend');
     var decision=btn.dataset.review;
     var label=resend?'ส่งอีเมลแจ้งอนุมัติอีกครั้ง':decision==='approve'?'อนุมัติ':'ไม่อนุมัติ';
     if(!window.confirm('ยืนยัน'+label+'สำหรับบัญชีนี้?'))return;
     btn.disabled=true;
     try{
       var r=resend
         ?await window.bang1Bridge.adminRequest('admin_account_resend_notice',{id:id})
         :await window.bang1Bridge.adminRequest('admin_account_review',{id:id,decision:decision});
       byId('bang1ApplicationsStatus').textContent=r.message;
       await loadApplications(true);
     }catch(err){byId('bang1ApplicationsStatus').textContent=err.message}
     finally{btn.disabled=false}
   });
 }
 async function loadApplications(preserve){
   var rows=byId('bang1ApplicationsRows');
   if(!rows)return;
   rows.innerHTML='<tr><td colspan="7">กำลังโหลดคำขอ...</td></tr>';
   if(!preserve)byId('bang1ApplicationsStatus').textContent='';
   try{
     var r=await window.bang1Bridge.adminRequest('admin_account_applications');
     var records=Array.isArray(r.data)?r.data:[];
     rows.innerHTML=records.length?records.map(function(x){
       var pending=x.status==='pending';
       var label=pending?'รออนุมัติ':x.status==='approved'?'อนุมัติแล้ว':'ไม่อนุมัติ';
       var approved=x.status==='approved';
       var mailStatus=x.notification_status||'unknown';
       var mailLabel=!approved?'—':mailStatus==='sent'?'ส่งแล้ว (ระบบรับคำสั่ง)':mailStatus==='failed'?'ส่งไม่สำเร็จ':mailStatus==='sending'?'กำลังส่ง':'ไม่ทราบสถานะ (ข้อมูลเดิม)';
       var lastAttempt=x.notification_last_attempt_at||'';
       var attempts=Number(x.notification_attempts)||0;
       var retryAllowed=approved&&(!lastAttempt||(Date.now()-new Date(lastAttempt.replace(' ','T')).getTime())>=120000);
       var mailDetail=approved&&attempts?' <small>พยายามส่ง '+attempts+' ครั้ง'+(lastAttempt?' · ล่าสุด '+esc(lastAttempt):'')+'</small>':'';
       var actions=pending?'<div class="bang1-account-actions">'+
         '<button type="button" data-review="approve" data-id="'+Number(x.id)+'" class="bang1-account-approve">อนุมัติ</button>'+
         '<button type="button" data-review="reject" data-id="'+Number(x.id)+'" class="bang1-account-reject">ไม่อนุมัติ</button></div>':
         approved?'<div class="bang1-account-actions"><button type="button" data-resend data-id="'+Number(x.id)+'" class="bang1-account-resend"'+(retryAllowed?'':' disabled title="กรุณารออย่างน้อย 2 นาที"')+'>✉️ ส่งอีเมลอีกครั้ง</button></div>':'';
       return '<tr><td>'+esc(x.first_name)+' '+esc(x.last_name)+'</td><td>'+esc(x.school_name)+'</td>'+
         '<td>'+esc(x.email)+'</td><td>'+esc(x.created_at)+'</td>'+
         '<td><span class="'+(pending?'bang1-account-waiting':'bang1-account-done')+'">'+label+'</span></td>'+
         '<td><span class="bang1-account-mail-state '+(mailStatus==='failed'?'is-failed':mailStatus==='sent'?'is-sent':'')+'">'+mailLabel+'</span>'+mailDetail+'</td><td>'+actions+'</td></tr>';
     }).join(''):'<tr><td colspan="7">ยังไม่มีคำขอสมัครใหม่</td></tr>';
   }catch(err){rows.innerHTML='<tr><td colspan="7">'+esc(err.message)+'</td></tr>'}
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',build);
 else build();
})();
