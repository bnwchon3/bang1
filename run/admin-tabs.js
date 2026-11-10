/* Admin tabs based on the existing Google Sheets columns.
   Existing page UI is left unchanged; only the requested tab panels are added. */
(function(){
 'use strict';
 function el(s,root){return (root||document).querySelector(s)}
 function all(s,root){return Array.from((root||document).querySelectorAll(s))}
 function escapeText(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
 function api(action,data){
   if(!window.bang1Bridge || typeof window.bang1Bridge.adminRequest!=='function')
     return Promise.reject(Error('ไม่พบการเชื่อมต่อระบบ Admin'));
   return window.bang1Bridge.adminRequest(action,data);
 }
 function isAdmin(){var user=typeof currentUser==='undefined'?null:currentUser;return !!(user && ['Admin','TopAdmin','Manager'].includes(user.role))}
 function inform(panel,text,error){
   var box=el('[data-admin-message]',panel);
   if(box){box.textContent=text||'';box.style.color=error?'#fca5a5':'#a7f3d0';}
 }
 function fmtDate(v){return v?String(v).replace(' ','T').slice(0,16):''}
 function createPanel(key,title,tableHead,extras){
   var panel=document.createElement('div');
   panel.id='admin-sheet-'+key;
   panel.dataset.adminSheetPanel=key;
   panel.hidden=true;
   panel.innerHTML=
     '<div class="card"><div class="card-header"><div class="card-title">'+escapeText(title)+'</div></div>'+
     (extras||'')+
     '<div class="table-wrap"><div class="table-scroller"><table class="table">'+
     '<thead><tr>'+tableHead.map(function(h){return '<th>'+escapeText(h)+'</th>'}).join('')+'</tr></thead>'+
     '<tbody data-admin-rows="'+key+'"><tr><td colspan="'+tableHead.length+'">กำลังโหลด...</td></tr></tbody>'+
     '</table></div></div><p data-admin-message aria-live="polite"></p></div>';
   return panel;
 }
 function initialize(){
   var page=document.getElementById('page-admin');
   if(!page || document.getElementById('admin-sheet-tabs'))return;
   var subtitle=page.querySelector('.subtitle');
   if(!subtitle)return;
   var existing=subtitle.nextSibling;
   var tabs=document.createElement('div');
   tabs.id='admin-sheet-tabs';
   tabs.className='btn-row admin-sheet-tabs';
   tabs.setAttribute('role','tablist');
   tabs.setAttribute('aria-label','แท็บผู้ดูแลระบบ');
   var names=[['check','ตรวจสอบนักวิ่ง'],['settings','ตั้งค่าเปิด–ปิดระบบ'],['schools','โรงเรียน'],['coaches','ผู้ฝึกซ้อม'],['logs','ประวัติการใช้งาน'],['champions','คลังแชมป์เก่า'],['reset','รีเซ็ตข้อมูล'],['backup','สำรองข้อมูล']];
   names.forEach(function(pair){
     var b=document.createElement('button');b.type='button';b.className='btn btn-outline btn-xs';
     b.dataset.adminTab=pair[0];b.textContent=pair[1];
     b.setAttribute('role','tab');b.setAttribute('aria-selected','false');
     tabs.appendChild(b);
   });
   page.insertBefore(tabs,existing);
   var checkPanel=document.createElement('div');
   checkPanel.id='admin-sheet-check';
   checkPanel.dataset.adminSheetPanel='check';
   page.insertBefore(checkPanel,existing);
   // Keep every original Admin element, including its verification modal and script, intact.
   while(checkPanel.nextSibling)checkPanel.appendChild(checkPanel.nextSibling);
   var settings=createPanel('settings','ตั้งค่าเปิด–ปิดระบบ',['รหัสรายการ','ชื่อรายการ','เวลาเปิดลงทะเบียน','เวลาปิดลงทะเบียน','สถานะ','บันทึก']);
   var schools=createPanel('schools','โรงเรียน',['ช่อง LIVE','SchoolID','ตราสัญลักษณ์โรงเรียน','SchoolName','schoolnickname','Active','บันทึก'],
     '<p class="school-branding-tip">🏃 ใช้ปุ่มขึ้น–ลงหรือลากที่จุดจับ เพื่อกำหนดลำดับช่องซ้ายไปขวา แล้วกด “บันทึกลำดับจอ LIVE” · ตราสัญลักษณ์ PNG/JPG/WebP จะไม่บิดเป็นผืนธง</p>');
   const schoolToolbar=document.createElement('div');
   schoolToolbar.className='school-order-toolbar';
   schoolToolbar.innerHTML='<div><strong>จัดตำแหน่งช่องโรงเรียน</strong><small>กำหนดลำดับบนจอ LIVE · ไม่เปลี่ยนเลขลู่วิ่งหรือผลการแข่งขัน</small></div>'+
     '<button type="button" class="btn btn-primary" data-save-school-order>บันทึกลำดับจอ LIVE</button>';
   schools.querySelector('.card').insertBefore(schoolToolbar,schools.querySelector('.table-wrap'));
   var coaches=createPanel('coaches','ผู้ฝึกซ้อม',['CoachID','Name','Email','SchoolName','Role','สิทธิ์เช็กชื่อ','รหัสผ่าน','จัดการ'],
     '<form id="admin-coach-form" autocomplete="off">'+
       '<input name="id" type="hidden">'+
       '<div class="form-grid">'+
         '<div class="field"><label>ชื่อผู้ฝึกซ้อม</label><input name="name" class="input" required></div>'+
         '<div class="field"><label>Email</label><input name="email" type="email" class="input" required></div>'+
         '<div class="field"><label>SchoolName</label><select name="schoolName" class="input" required></select></div>'+
         '<div class="field"><label>Role</label><select name="role" class="input"><option value="Admin">Admin</option><option value="Manager">Manager</option><option value="Teacher">Teacher</option><option value="Staff">Staff</option></select></div>'+
         '<div class="field"><label>รหัสผ่านใหม่ (ไม่กรอกหากไม่เปลี่ยน)</label><input name="password" type="password" class="input" autocomplete="new-password" minlength="8"></div>'+
       '</div>'+
       '<div class="btn-row"><button class="btn btn-primary" type="submit">บันทึกผู้ฝึกซ้อม</button>'+
       '<button class="btn btn-outline" type="reset">เพิ่มผู้ฝึกซ้อมใหม่ / ล้างฟอร์ม</button></div>'+
     '</form>');
   var logs=createPanel('logs','ประวัติการใช้งาน',
      ['Timestamp','ActionCode','ActionTextTH','Module','TargetType','TargetId','TargetName','RunnerID','RegID','EventName','UserEmail','UserName','UserRole','UserSchool','Detail'],
      '<div class="btn-row"><input id="admin-logs-search" class="input" type="search" placeholder="ค้นหาประวัติการใช้งาน">'+
      '<button id="admin-logs-find" type="button" class="btn btn-outline">ค้นหา</button></div>');

   // Separate administrative reset feature; nothing is deleted until a second confirmed action.
   var reset=document.createElement('div');
   reset.id='admin-sheet-reset';
   reset.dataset.adminSheetPanel='reset';
   reset.hidden=true;
   var resetSections=[
     ['runners','ฐานข้อมูลนักวิ่ง'],
     ['coaches','ฐานข้อมูลครูผู้ฝึกซ้อม'],
     ['registrations','ฐานข้อมูลรายการสมัคร'],
     ['photos','เคลียร์รูปภาพหลักฐานนักวิ่ง'],
     ['results','รีเซ็ตผลการแข่งขัน'],
     ['logs','log การทำรายการ']
   ];
   reset.innerHTML='<div class="card"><div class="card-header"><div class="card-title">รีเซ็ตข้อมูล</div></div>'+
      '<p>เลือกหมวดที่ต้องการล้างเท่านั้น ระบบจะไม่ลบข้อมูลจนกว่าจะตรวจสอบจำนวนและยืนยันอีกครั้ง</p>'+
      '<div id="admin-reset-choices">'+resetSections.map(([value,label])=>
         '<label style="display:flex;align-items:center;gap:10px;margin:10px 0;cursor:pointer">'+
         '<input type="checkbox" data-reset-section="'+value+'"> '+escapeText(label)+'</label>').join('')+'</div>'+
      '<p style="font-size:.84rem;color:#94a3b8">'+
      'หมายเหตุ: การเคลียร์รูปและหลักฐานจะล้างลิงก์ใน MariaDB แต่ไม่ลบไฟล์ต้นฉบับออกจาก Google Drive '+
      'และจะเก็บข้อมูลนำเข้าเดิมไว้ใน source_rows</p>'+
      '<div class="btn-row"><button type="button" id="admin-reset-preview" class="btn btn-outline">ตรวจสอบรายการที่จะล้าง</button></div>'+
      '<div id="admin-reset-confirmation" hidden>'+
      '<p id="admin-reset-summary" style="white-space:pre-line"></p>'+
      '<label>พิมพ์คำว่า <strong>ล้างข้อมูล</strong> เพื่อยืนยัน'+
      '<input type="text" id="admin-reset-phrase" class="input" autocomplete="off" placeholder="ล้างข้อมูล"></label>'+
      '<label>รหัสผ่านเข้าสู่ระบบของ Admin'+
      '<input type="password" id="admin-reset-login-password" class="input" autocomplete="current-password" placeholder="รหัสเข้าสู่ระบบ Admin"></label>'+
      '<div class="btn-row"><button type="button" id="admin-reset-execute" class="btn btn-primary" disabled>ยืนยันรีเซ็ตข้อมูลที่เลือก</button></div>'+
      '</div><p data-admin-message role="status" aria-live="polite"></p></div>';
   // Permanent championship archive. Reset history starts as UNVERIFIED so
   // test scores cannot become a publicly advertised former champion.
   const champions=document.createElement('div');
   champions.id='admin-sheet-champions';
   champions.dataset.adminSheetPanel='champions';
   champions.hidden=true;
   champions.innerHTML=
     '<div class="card"><div class="card-header"><div class="card-title">🏆 คลังประวัติแชมป์เก่า / ตัวเต็ง</div></div>'+
     '<p>สำรองผลการแข่งขันก่อนล้างข้อมูลเพื่อใช้อ้างอิงบน Sport Showcase '+
     'เฉพาะประวัติที่ Admin ตรวจและรับรองว่าเป็นผลแข่งขันจริงเท่านั้นที่จะขึ้นเป็นแชมป์เก่า</p>'+
     '<div class="btn-row"><button type="button" id="admin-champion-preview-button" class="btn btn-outline">ตรวจผลรอบชิงที่มีอยู่</button></div>'+
     '<div id="admin-champion-preview-result" class="admin-champion-preview" role="status" aria-live="polite">ยังไม่ได้ตรวจสอบผล</div>'+
     '<form id="admin-champion-form" autocomplete="off">'+
       '<div class="form-grid">'+
         '<div class="field"><label>ปีการแข่งขันเดิม / ชื่อชุดประวัติ</label><input id="admin-champion-season" class="input" maxlength="70" required placeholder="เช่น ปีการศึกษา 2568 (ผลจริง)"></div>'+
         '<div class="field"><label>รหัสผ่าน Admin เพื่อยืนยัน</label><input id="admin-champion-password" type="password" class="input" required autocomplete="current-password"></div>'+
         '<div class="field"><label>พิมพ์คำว่า ยืนยันแชมป์เก่า เมื่อได้ตรวจผลจริงแล้ว</label><input id="admin-champion-confirmation" class="input" placeholder="ยืนยันแชมป์เก่า" autocomplete="off" required></div>'+
       '</div>'+
       '<div class="btn-row"><button type="submit" class="btn btn-primary">📁 บันทึกแชมป์เก่าและรับรองผลจริง</button></div>'+
     '</form>'+
     '<p class="admin-champion-warning">⚠️ หากเป็นข้อมูลทดสอบ ห้ามยืนยันว่าเป็นแชมป์จริง '+
     'การรีเซ็ตข้อมูลจะเก็บผลเป็นประวัติที่ “ยังไม่รับรอง” โดยอัตโนมัติและไม่แสดงบน Sport</p>'+
     '<p data-admin-message role="status" aria-live="polite"></p></div>'+
     '<div class="card"><div class="card-header"><div class="card-title">ประวัติที่เก็บไว้ใน MariaDB</div></div>'+
     '<div class="table-wrap"><div class="table-scroller"><table class="table"><thead><tr>'+
       '<th>ชุดข้อมูล / ปีแข่งขัน</th><th>บันทึกเมื่อ</th><th>ผลที่เก็บ</th>'+
       '<th>แชมป์รอบชิง</th><th>สถานะ</th><th>จัดการ</th>'+
     '</tr></thead><tbody id="admin-champion-archives"><tr><td colspan="6">กำลังโหลด...</td></tr></tbody>'+
     '</table></div></div></div>';

   // Explicitly requested backup and restore tab, using the same card and form styles.
   const backup=document.createElement('div');
   backup.id='admin-sheet-backup';
   backup.dataset.adminSheetPanel='backup';
   backup.hidden=true;
   backup.innerHTML=
      '<div class="card"><div class="card-header"><div class="card-title">สำรองข้อมูล</div></div>'+
      '<p>ดาวน์โหลดไฟล์ Backup ของข้อมูล MariaDB แบบเข้ารหัส โดยไม่เก็บไฟล์ไว้บนเว็บไซต์</p>'+
      '<form id="admin-backup-export-form" autocomplete="off">'+
      '<div class="form-grid">'+
      '<div class="field"><label>รหัสผ่านเข้าสู่ระบบ Admin</label><input type="password" class="input" name="adminPassword" required autocomplete="current-password"></div>'+
      '<div class="field"><label>ตั้งรหัสป้องกันไฟล์ Backup (อย่างน้อย 12 ตัวอักษร)</label><input type="password" class="input" name="backupPassphrase" minlength="12" required autocomplete="new-password"></div>'+
      '</div><div class="btn-row"><button type="submit" class="btn btn-primary">ดาวน์โหลดไฟล์ Backup</button></div></form>'+
      '<p style="font-size:.84rem;color:#94a3b8">เก็บไฟล์และรหัสป้องกันไฟล์เป็นความลับ หากลืมรหัสไฟล์จะไม่สามารถกู้คืนได้ '+
      'รูปภาพจริงใน Google Drive ไม่ได้รวมใน Backup มีเพียงลิงก์อ้างอิง</p>'+
      '<p data-admin-message role="status" aria-live="polite"></p></div>'+
      '<div class="card"><div class="card-header"><div class="card-title">กู้คืนข้อมูล (Restore)</div></div>'+
      '<p>เลือกไฟล์ .bnwbackup แล้วตรวจสอบข้อมูลก่อนยืนยัน ระบบจะไม่แทนที่ฐานข้อมูลทันที</p>'+
      '<form id="admin-backup-restore-form" autocomplete="off">'+
      '<div class="form-grid">'+
      '<div class="field"><label>ไฟล์ Backup</label><input type="file" class="input" name="backup" accept=".bnwbackup" required></div>'+
      '<div class="field"><label>รหัสป้องกันไฟล์ Backup</label><input type="password" class="input" name="backupPassphrase" required autocomplete="off"></div>'+
      '<div class="field"><label>รหัสผ่านเข้าสู่ระบบ Admin</label><input type="password" class="input" name="adminPassword" required autocomplete="current-password"></div>'+
      '</div><div class="btn-row"><button type="button" id="admin-backup-check" class="btn btn-outline">ตรวจสอบไฟล์ก่อนกู้คืน</button></div>'+
      '<div id="admin-backup-restore-confirm" hidden>'+
      '<p id="admin-backup-restore-summary" style="white-space:pre-line"></p>'+
      '<label>พิมพ์คำว่า <strong>กู้คืนข้อมูล</strong> เพื่อยืนยัน'+
      '<input type="text" id="admin-backup-confirm-text" class="input" autocomplete="off" placeholder="กู้คืนข้อมูล"></label>'+
      '<div class="btn-row"><button type="button" class="btn btn-primary" id="admin-backup-do-restore" disabled>ยืนยันกู้คืนข้อมูล</button></div>'+
      '</div></form><p data-admin-message role="status" aria-live="polite"></p></div>';
   [settings,schools,coaches,logs,champions,reset,backup].forEach(function(p){page.appendChild(p)});



   // Filtering is limited to the coach tab; preserve the original Admin page layout.
   const coachFilter=document.createElement('div');
   coachFilter.className='btn-row';
   coachFilter.innerHTML='<label for="admin-coach-school-filter">กรองตามโรงเรียน</label>'+
     '<select id="admin-coach-school-filter" class="input">'+
     '<option value="">ทั้งหมด</option></select>';
   coaches.querySelector('.card').insertBefore(coachFilter,coaches.querySelector('.table-wrap'));
   const resetDialog=document.createElement('dialog');
   resetDialog.id='admin-coach-reset-dialog';
   resetDialog.style.cssText='max-width:min(95vw,540px);border-radius:12px;padding:20px;border:1px solid #334155;background:#0f172a;color:#e5e7eb;box-shadow:0 20px 70px #0009';
   resetDialog.innerHTML='<h3>รหัสผ่านใหม่ของผู้ฝึกซ้อม</h3>'+
      '<p>ระบบไม่สามารถแสดงรหัสผ่านเดิมได้ รหัสใหม่นี้จะแสดงเพียงครั้งเดียว โปรดแจ้งเจ้าของบัญชีโดยตรง</p>'+
      '<label>รหัสผ่านใหม่<input id="admin-coach-reset-value" type="text" class="input" readonly autocomplete="off"></label>'+
      '<div class="btn-row"><button type="button" id="admin-coach-copy-password" class="btn btn-primary">คัดลอกรหัสผ่าน</button>'+
      '<button type="button" id="admin-coach-close-password" class="btn btn-outline">ปิด</button></div>';
   page.appendChild(resetDialog);
   let resetTimeout=null;
   function clearResetSecret(){
     if(resetTimeout)clearTimeout(resetTimeout);
     resetTimeout=null;
     el('#admin-coach-reset-value',resetDialog).value='';
   }
   resetDialog.addEventListener('close',clearResetSecret);
   el('#admin-coach-close-password',resetDialog).onclick=()=>resetDialog.close();
   el('#admin-coach-copy-password',resetDialog).onclick=async ()=>{
      const field=el('#admin-coach-reset-value',resetDialog);
      if(!field.value)return;
      try{await navigator.clipboard.writeText(field.value);inform(coaches,'คัดลอกรหัสผ่านใหม่แล้ว');}
      catch(error){field.focus();field.select();inform(coaches,'เลือกข้อความรหัสผ่านแล้ว กรุณาคัดลอกด้วยตนเอง');}
   };
   function showResetSecret(secret){
     el('#admin-coach-reset-value',resetDialog).value=String(secret||'');
     resetDialog.showModal();
     resetTimeout=setTimeout(()=>{if(resetDialog.open)resetDialog.close()},120000);
   }

   var pager=document.createElement('div');
   pager.className='btn-row';
   pager.id='admin-logs-pager';
   pager.innerHTML='<button type="button" class="btn btn-outline btn-xs" data-logs-prev>ก่อนหน้า</button>'+
      '<span data-logs-position></span>'+
      '<button type="button" class="btn btn-outline btn-xs" data-logs-next>ถัดไป</button>';
   logs.querySelector('.card').appendChild(pager);
   var active='check';
   async function selectTab(key){
     if(!isAdmin())return;
     var user=typeof currentUser==='undefined'?null:currentUser;
     if(user&&user.role==='Manager'&&key!=='check')return;
     active=key;
     all('[data-admin-sheet-panel]',page).forEach(function(p){p.hidden=p.dataset.adminSheetPanel!==key});
     all('[data-admin-tab]',tabs).forEach(function(btn){
       var selected=btn.dataset.adminTab===key;
       btn.classList.toggle('btn-primary',selected);
       btn.classList.toggle('btn-outline',!selected);
       btn.setAttribute('aria-selected',selected?'true':'false');
     });
     if(key==='settings')await loadSettings();
     if(key==='schools')await loadSchools();
     if(key==='coaches')await loadCoaches();
     if(key==='logs')await loadLogs(1);
     if(key==='champions')await loadChampions();
     if(key==='reset')clearResetPreview();
     if(key==='backup')clearRestorePreview();
   }
   tabs.addEventListener('click',function(e){
     var b=e.target.closest('[data-admin-tab]');if(!b)return;
     selectTab(b.dataset.adminTab).catch(function(err){inform(el('#admin-sheet-'+b.dataset.adminTab),err.message,true)});
   });
   // Select verification by default. All existing verification functions stay unchanged.
   all('[data-admin-tab]',tabs)[0].classList.replace('btn-outline','btn-primary');
   all('[data-admin-tab]',tabs)[0].setAttribute('aria-selected','true');

   async function loadSettings(){
     var rows=el('[data-admin-rows="settings"]');
     rows.innerHTML='<tr><td colspan="6">กำลังโหลด...</td></tr>';
     var r=await api('admin_settings');
     rows.innerHTML=(r.data||[]).map(function(x){
       var status=String(x.status||'');
       var options=[['','ตามเวลาเปิด–ปิด'],['เปิดระบบ','เปิดระบบ'],['ปิดระบบ','ปิดระบบ']];
       return '<tr data-settings-id="'+escapeText(x.id)+'">'+
         '<td>'+escapeText(x.id)+'</td><td>'+escapeText(x.name)+'</td>'+
         '<td><input data-open-at class="input" type="datetime-local" value="'+escapeText(fmtDate(x.opens_at))+'"></td>'+
         '<td><input data-close-at class="input" type="datetime-local" value="'+escapeText(fmtDate(x.closes_at))+'"></td>'+
         '<td><select data-setting-status class="input">'+options.map(function(o){return '<option value="'+o[0]+'"'+(status===o[0]?' selected':'')+'>'+o[1]+'</option>'}).join('')+'</select></td>'+
         '<td><button type="button" class="btn btn-primary btn-xs" data-save-setting>บันทึก</button></td></tr>';
     }).join('')||'<tr><td colspan="6">ยังไม่มีรายการ</td></tr>';
     inform(settings,'');
   }
   settings.addEventListener('click',async function(e){
     var b=e.target.closest('[data-save-setting]');if(!b)return;
     var tr=b.closest('tr'),payload={id:tr.dataset.settingsId,
       opensAt:el('[data-open-at]',tr).value,closesAt:el('[data-close-at]',tr).value,
       status:el('[data-setting-status]',tr).value};
     b.disabled=true;
     try{var r=await api('admin_settings_save',payload);inform(settings,r.message||'บันทึกสำเร็จ');}
     catch(err){inform(settings,err.message,true)}
     finally{b.disabled=false}
   });

   function schoolFlagMarkup(logoUrl){
     return '<span class="school-flag school-flag-admin" aria-label="ตัวอย่างตราสัญลักษณ์โรงเรียน">'+
       '<span class="school-flag-fabric">'+
       (logoUrl?'<img class="school-flag-logo" src="'+escapeText(logoUrl)+
          '" alt="ตราสัญลักษณ์โรงเรียน" loading="lazy">':
          '<span class="school-flag-empty">🏫</span>')+
       '</span></span>';
   }
   function liveOrderCell(){
     return '<td class="school-order-cell"><strong data-school-position>—</strong>'+
       '<div class="school-order-actions">'+
       '<button type="button" class="btn btn-outline btn-xs" data-move-school="up" title="เลื่อนช่องไปซ้าย" aria-label="เลื่อนช่องไปซ้าย">↑</button>'+
       '<button type="button" class="btn btn-outline btn-xs" data-move-school="down" title="เลื่อนช่องไปขวา" aria-label="เลื่อนช่องไปขวา">↓</button>'+
       '<span data-drag-school draggable="true" class="school-drag-handle" title="ลากสลับลำดับ" aria-label="ลากสลับลำดับ">⠿</span>'+
       '</div></td>';
   }
   function updateSchoolPositions(){
     const list=all('tr[data-school-id]',el('[data-admin-rows="schools"]'))
       .filter(tr=>Number(tr.dataset.schoolId)>0);
     list.forEach((tr,i)=>{
       const label=el('[data-school-position]',tr);
       if(label)label.textContent=String(i+1).padStart(2,'0');
       const up=el('[data-move-school="up"]',tr),down=el('[data-move-school="down"]',tr);
       if(up)up.disabled=i===0;
       if(down)down.disabled=i===list.length-1;
     });
   }
   function schoolLogoCell(school){
     const id=Number(school.id||0);
     const existing=Boolean(id);
     return '<div class="school-logo-editor">'+
       schoolFlagMarkup(school.logoUrl||'')+
       '<div class="school-logo-controls">'+
         '<label class="btn btn-outline btn-xs school-logo-choose">'+
           '🖼️ '+(school.logoUrl?'เปลี่ยนตรา':'เลือกภาพ')+
           '<input type="file" data-school-logo-file accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"'+
             (existing?'':' disabled')+'></label>'+
         '<button class="btn btn-primary btn-xs" type="button" data-upload-school-logo '+
           'disabled>อัปโหลด</button>'+
         (existing?'':'<small>เพิ่มโรงเรียนก่อนแนบรูป</small>')+
       '</div></div>';
   }
   async function loadSchools(){
     var rows=el('[data-admin-rows="schools"]');
     rows.innerHTML='<tr><td colspan="7">กำลังโหลด...</td></tr>';
     var r=await api('admin_schools');
     var options=['Active','Inactive'];
     rows.innerHTML=(r.data||[]).map(function(x){
       return '<tr data-school-id="'+escapeText(x.id)+'">'+liveOrderCell()+'<td>'+escapeText(x.id)+'</td>'+
         '<td>'+schoolLogoCell(x)+'</td>'+
         '<td><input class="input" data-school-name value="'+escapeText(x.name)+'"></td>'+
         '<td><input class="input" data-school-nickname value="'+escapeText(x.nickname)+'"></td>'+
         '<td><select class="input" data-school-active>'+options.map(function(value){
           return '<option'+(x.active===value?' selected':'')+'>'+value+'</option>'}).join('')+'</select></td>'+
         '<td><button type="button" class="btn btn-primary btn-xs" data-save-school>บันทึก</button></td></tr>';
     }).join('')+'<tr data-school-id="0"><td>—</td><td>ใหม่</td>'+
       '<td>'+schoolLogoCell({id:0})+'</td>'+
       '<td><input class="input" data-school-name placeholder="SchoolName"></td>'+
       '<td><input class="input" data-school-nickname placeholder="schoolnickname"></td>'+
       '<td><select class="input" data-school-active><option>Active</option><option>Inactive</option></select></td>'+
       '<td><button type="button" class="btn btn-primary btn-xs" data-save-school>เพิ่ม</button></td></tr>';
     updateSchoolPositions();
     inform(schools,'');
   }
   // Drag handles for desktop; arrows also work on touch devices.
   let draggedSchoolRow=null;
   schools.addEventListener('dragstart',function(e){
     const handle=e.target.closest('[data-drag-school]');
     if(!handle)return;
     draggedSchoolRow=handle.closest('tr[data-school-id]');
     if(!draggedSchoolRow||Number(draggedSchoolRow.dataset.schoolId)<1)return;
     e.dataTransfer.effectAllowed='move';
     e.dataTransfer.setData('text/plain',draggedSchoolRow.dataset.schoolId);
     draggedSchoolRow.classList.add('is-dragging');
   });
   schools.addEventListener('dragover',function(e){
     if(!draggedSchoolRow)return;
     const target=e.target.closest('tr[data-school-id]');
     if(!target||target===draggedSchoolRow||Number(target.dataset.schoolId)<1)return;
     e.preventDefault();
     const bounds=target.getBoundingClientRect();
     const after=e.clientY>bounds.top+bounds.height/2;
     target.parentElement.insertBefore(draggedSchoolRow,after?target.nextSibling:target);
     updateSchoolPositions();
   });
   schools.addEventListener('drop',function(e){
     if(!draggedSchoolRow)return;
     e.preventDefault();
     draggedSchoolRow.classList.remove('is-dragging');
     draggedSchoolRow=null;updateSchoolPositions();
   });
   schools.addEventListener('dragend',function(){
     if(draggedSchoolRow)draggedSchoolRow.classList.remove('is-dragging');
     draggedSchoolRow=null;updateSchoolPositions();
   });
   schools.addEventListener('change',function(e){
     const input=e.target.closest('[data-school-logo-file]');
     if(!input)return;
     const tr=input.closest('tr');
     const file=input.files&&input.files[0];
     const upload=el('[data-upload-school-logo]',tr);
     upload.disabled=!file;
     if(!file)return;
     if(!/^image\/(png|jpeg|webp)$/.test(file.type)||file.size>3*1024*1024||file.size<=0){
       upload.disabled=true;
       input.value='';
       inform(schools,'รูปภาพต้องเป็น PNG, JPG หรือ WebP และไม่เกิน 3 MB',true);return;
     }
     const fabric=el('.school-flag-fabric',tr);
     const image=document.createElement('img');
     image.className='school-flag-logo';
     image.alt='ตัวอย่างตราโรงเรียนที่เลือก';
     const temporary=URL.createObjectURL(file);
     image.src=temporary;
     image.addEventListener('load',()=>URL.revokeObjectURL(temporary),{once:true});
     image.addEventListener('error',()=>URL.revokeObjectURL(temporary),{once:true});
     fabric.replaceChildren(image);
     inform(schools,'เลือกภาพแล้ว กด “อัปโหลด” เพื่อบันทึกตราโรงเรียน');
   });
   schools.addEventListener('click',async function(e){
     const move=e.target.closest('[data-move-school]');
     if(move){
       const tr=move.closest('tr[data-school-id]');
       const sibling=move.dataset.moveSchool==='up'?tr.previousElementSibling:tr.nextElementSibling;
       if(sibling&&Number(sibling.dataset.schoolId)>0){
         const parent=tr.parentElement;
         if(move.dataset.moveSchool==='up')parent.insertBefore(tr,sibling);
         else parent.insertBefore(sibling,tr);
         updateSchoolPositions();
         inform(schools,'จัดลำดับแล้ว กด “บันทึกลำดับจอ LIVE” เพื่อเผยแพร่');
       }
       return;
     }
     const saveOrder=e.target.closest('[data-save-school-order]');
     if(saveOrder){
       const schoolIds=all('tr[data-school-id]',el('[data-admin-rows="schools"]'))
         .map(tr=>Number(tr.dataset.schoolId)).filter(id=>id>0);
       if(!schoolIds.length){inform(schools,'ไม่พบรายการโรงเรียน',true);return;}
       saveOrder.disabled=true;
       try{
         const result=await api('admin_school_order_save',{schoolIds});
         await loadSchools();
         inform(schools,result.message||'บันทึกลำดับจอ LIVE เรียบร้อย');
       }catch(err){inform(schools,err.message||'บันทึกลำดับไม่สำเร็จ',true)}
       finally{saveOrder.disabled=false}
       return;
     }
     const upload=e.target.closest('[data-upload-school-logo]');
     if(upload){
       const tr=upload.closest('tr');
       const input=el('[data-school-logo-file]',tr);
       const file=input.files&&input.files[0];
       const schoolId=Number(tr.dataset.schoolId);
       if(!schoolId||!file){inform(schools,'กรุณาเลือกไฟล์และบันทึกโรงเรียนก่อนอัปโหลด',true);return}
       const form=new FormData();
       form.append('schoolId',String(schoolId));
       form.append('logo',file,file.name);
       upload.disabled=true;
       try{
         const result=await api('admin_school_logo_upload',form);
         await loadSchools();
         inform(schools,result.message||'อัปโหลดสัญลักษณ์โรงเรียนสำเร็จ');
       }catch(error){
         inform(schools,error.message||'อัปโหลดโลโก้ไม่สำเร็จ',true);
         upload.disabled=false;
       }
       return;
     }
     const save=e.target.closest('[data-save-school]');
     if(!save)return;
     const tr=save.closest('tr');
     const payload={id:tr.dataset.schoolId,name:el('[data-school-name]',tr).value,
       nickname:el('[data-school-nickname]',tr).value,active:el('[data-school-active]',tr).value};
     save.disabled=true;
     try{
       const result=await api('admin_school_save',payload);
       await loadSchools();
       inform(schools,result.message||'บันทึกสำเร็จ');
     }catch(error){inform(schools,error.message,true)}
     finally{save.disabled=false}
   });
   var coachList=[];
   function populateCoachSchoolFilter(){
     const select=el('#admin-coach-school-filter',coaches);
     const previous=select.value;
     const schools=Array.from(new Set(coachList.map(x=>String(x.school_name||'').trim()).filter(Boolean)))
        .sort((a,b)=>a.localeCompare(b,'th'));
     select.innerHTML='<option value="">ทั้งหมด</option>'+
       schools.map(name=>'<option value="'+escapeText(name)+'">'+escapeText(name)+'</option>').join('');
     select.value=schools.includes(previous)?previous:'';
   }
   function renderCoachRows(){
     var rows=el('[data-admin-rows="coaches"]');
     var select=el('#admin-coach-school-filter',coaches);
     var selected=select.value;
     var visible=selected?coachList.filter(x=>String(x.school_name||'')===selected):coachList;
     const user=typeof currentUser==='undefined'?null:currentUser;
     rows.innerHTML=visible.map(function(x){
       const own=user&&String(x.id)===String(user.coachId);
       return '<tr><td>'+escapeText(x.id)+'</td><td>'+escapeText(x.name)+'</td>'+
         '<td>'+escapeText(x.email)+'</td><td>'+escapeText(x.school_name)+'</td><td>'+escapeText(x.role)+'</td>'+
         '<td>'+(x.role==='Manager'
           ?'<button type="button" data-coach-checkin="'+escapeText(x.id)+'" class="btn btn-xs '+
             (Number(x.can_live_checkin)===1?'btn-primary':'btn-outline')+'" title="Admin กำหนดสิทธิ์รายบุคคล">'+
             (Number(x.can_live_checkin)===1?'✓ อนุญาตแล้ว · ถอนสิทธิ์':'🔒 ยังไม่อนุญาต · ให้สิทธิ์')+'</button>'
           :(['Admin','TopAdmin'].includes(x.role)?'✓ Admin':'—'))+'</td>'+
         '<td><button type="button" data-reset-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs">ตั้งรหัสผ่านใหม่</button></td>'+
         '<td><button type="button" data-edit-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs">แก้ไข</button> '+
         '<button type="button" data-delete-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs"'+
         (own?' disabled title="ไม่สามารถลบบัญชีตนเอง"':'')+'>ลบ</button></td></tr>';
     }).join('')||'<tr><td colspan="8">ไม่พบผู้ฝึกซ้อมในโรงเรียนที่เลือก</td></tr>';
   }
   el('#admin-coach-school-filter',coaches).addEventListener('change',renderCoachRows);
   async function loadCoaches(){
     var rows=el('[data-admin-rows="coaches"]');
     rows.innerHTML='<tr><td colspan="8">กำลังโหลด...</td></tr>';
     var rs=await Promise.all([api('admin_coaches'),api('admin_schools')]);
     coachList=rs[0].data||[];
     var form=el('#admin-coach-form');
     var schoolSelect=el('[name="schoolName"]',form);
     var schoolNames=(rs[1].data||[]).map(function(x){return x.name});
     if(!schoolNames.includes('ผู้ดูแลระบบ'))schoolNames.unshift('ผู้ดูแลระบบ');
     schoolSelect.innerHTML='<option value="">-- เลือกโรงเรียน --</option>'+
       schoolNames.map(function(s){return '<option value="'+escapeText(s)+'">'+escapeText(s)+'</option>'}).join('');
     populateCoachSchoolFilter();
     renderCoachRows();
   }
   coaches.addEventListener('click',async function(e){
     const permission=e.target.closest('[data-coach-checkin]');
     if(permission){
       const manager=coachList.find(x=>String(x.id)===permission.dataset.coachCheckin);
       if(!manager||manager.role!=='Manager')return;
       const allow=Number(manager.can_live_checkin)!==1;
       const question=allow
         ?'อนุญาตให้ '+manager.name+' เข้าเมนูเช็กชื่อรายงานตัวและควบคุมจอ Live หรือไม่?'
         :'ถอนสิทธิ์เช็กชื่อรายงานตัวของ '+manager.name+' หรือไม่? ระบบจะปฏิเสธการทำรายการทันที';
       if(!confirm(question))return;
       permission.disabled=true;
       try{
         const result=await api('admin_coach_live_checkin_access',{id:Number(manager.id),allowed:allow});
         await loadCoaches();
         inform(coaches,result.message||'บันทึกสิทธิ์เรียบร้อยแล้ว');
       }catch(error){inform(coaches,error.message||'บันทึกสิทธิ์ไม่สำเร็จ',true)}
       finally{permission.disabled=false}
       return;
     }
     var edit=e.target.closest('[data-edit-coach]');
     if(edit){
       var x=coachList.find(x=>String(x.id)===edit.dataset.editCoach);
       if(!x)return;
       var form=el('#admin-coach-form');
       form.elements.id.value=x.id;
       form.elements.name.value=x.name||'';
       form.elements.email.value=x.email||'';
       form.elements.schoolName.value=x.school_name||'';
       form.elements.role.value=x.role;
       form.elements.password.value='';
       form.scrollIntoView({behavior:'smooth',block:'start'});
       return;
     }
     var reset=e.target.closest('[data-reset-coach]');
     if(reset){
       const x=coachList.find(x=>String(x.id)===reset.dataset.resetCoach);
       if(!x)return;
       if(!confirm('ยืนยันออกรหัสผ่านใหม่ให้ '+x.name+' ?\nรหัสผ่านเดิมจะใช้ไม่ได้ทันที'))return;
       reset.disabled=true;
       try{
         const result=await api('admin_coach_reset_password',{id:x.id});
         if(!result.password)throw Error('เซิร์ฟเวอร์ไม่ได้ส่งรหัสผ่านใหม่');
         showResetSecret(result.password);
         inform(coaches,'ออกรหัสใหม่แล้ว กรุณาแจ้งผู้ฝึกซ้อมด้วยตนเอง');
       }catch(error){inform(coaches,error.message,true)}
       finally{reset.disabled=false}
       return;
     }
     var del=e.target.closest('[data-delete-coach]');
     if(del){
       const x=coachList.find(x=>String(x.id)===del.dataset.deleteCoach);
       if(!x)return;
       if(!confirm('ยืนยันลบบัญชีผู้ฝึกซ้อม '+x.name+' ('+x.email+') ?\nการลบจะยกเลิกสิทธิ์เข้าสู่ระบบ แต่จะเก็บข้อมูลนักวิ่ง ใบสมัคร และประวัติเดิมไว้'))return;
       del.disabled=true;
       try{
         const result=await api('admin_coach_delete',{id:x.id});
         var form=el('#admin-coach-form');
         if(String(form.elements.id.value)===String(x.id)){form.reset();form.elements.id.value='';}
         await loadCoaches();
         inform(coaches,result.message||'ลบบัญชีเรียบร้อยแล้ว');
       }catch(error){inform(coaches,error.message,true);del.disabled=false}
     }
   });
   var coachForm=el('#admin-coach-form');
   coachForm.addEventListener('submit',async function(e){
     e.preventDefault();
     var payload={id:coachForm.elements.id.value,
       name:coachForm.elements.name.value,email:coachForm.elements.email.value,
       schoolName:coachForm.elements.schoolName.value,role:coachForm.elements.role.value,
       password:coachForm.elements.password.value};
     var save=el('[type="submit"]',coachForm);save.disabled=true;
     try{
       var r=await api('admin_coach_save',payload);
       coachForm.reset();coachForm.elements.id.value='';
       await loadCoaches();inform(coaches,r.message||'บันทึกสำเร็จ');
     }catch(err){inform(coaches,err.message,true)}
     finally{save.disabled=false;coachForm.elements.password.value=''}
   });
   coachForm.addEventListener('reset',function(){setTimeout(function(){coachForm.elements.id.value=''},0)});

   // Separate historical score archive workflow, isolated from resetting.
   async function loadChampions(){
     const preview=el('#admin-champion-preview-result',champions);
     const list=el('#admin-champion-archives',champions);
     list.innerHTML='<tr><td colspan="6">กำลังโหลด...</td></tr>';
     const data=await api('admin_champions_preview');
     const found=(data.samples||[]).map(x=>'• '+x.eventName+' · '+
       'แชมป์ '+x.school+' (ฮีท '+x.heat+')');
     preview.textContent='มีผลที่บันทึกไว้ '+(data.rows||0)+' รายการ · '+
       'พบแชมป์รอบชิงที่ไม่ขัดแย้ง '+(data.finalWinners||0)+' รายการ\n'+
       (found.length?found.slice(0,8).join('\n'):'ยังไม่มีผลรอบชิงอันดับ 1 ที่บันทึกไว้')+
       '\n\nโปรดตรวจว่าเป็นผลการแข่งขันจริง ไม่ใช่ข้อมูลทดสอบ';
     const items=data.archives||[];
     list.innerHTML=items.map(x=>
       '<tr data-champion-id="'+escapeText(x.id)+'">'+
       '<td>'+escapeText(x.season_label)+'</td>'+
       '<td>'+escapeText(x.captured_at)+'</td>'+
       '<td>'+escapeText(x.result_count)+'</td>'+
       '<td>'+escapeText(x.final_count)+'</td>'+
       '<td>'+(Number(x.is_confirmed)?'<span class="admin-champion-good">รับรองแล้ว</span>':
         '<span class="admin-champion-pending">ยังไม่รับรอง</span>')+'</td>'+
       '<td>'+(Number(x.is_confirmed)||!Number(x.final_count)?'—':
         '<button class="btn btn-outline btn-xs" type="button" data-confirm-champion="'+
         escapeText(x.id)+'">ตรวจและรับรอง</button>')+'</td></tr>'
     ).join('')||'<tr><td colspan="6">ยังไม่มีคลังประวัติแชมป์เก่า</td></tr>';
     inform(champions,'');
   }
   el('#admin-champion-preview-button',champions).addEventListener('click',async event=>{
     const btn=event.currentTarget;
     btn.disabled=true;
     try{await loadChampions()}catch(e){inform(champions,e.message,true)}
     finally{btn.disabled=false}
   });
   const championsForm=el('#admin-champion-form',champions);
   championsForm.addEventListener('submit',async event=>{
     event.preventDefault();
     const season=el('#admin-champion-season',champions).value.trim();
     const confirmation=el('#admin-champion-confirmation',champions).value.trim();
     const password=el('#admin-champion-password',champions).value;
     if(!season||confirmation!=='ยืนยันแชมป์เก่า'||!password){
       inform(champions,'กรุณาระบุปีแข่งขัน รหัส Admin และคำยืนยันตามกำหนด',true);return;
     }
     if(!window.confirm('ยืนยันว่าผลรอบชิงในระบบเป็นผลการแข่งขันจริง ไม่ใช่ข้อมูลทดสอบ?'))return;
     const button=championsForm.querySelector('[type="submit"]');
     button.disabled=true;
     try{
       const response=await api('admin_champions_archive',
         {season,confirmation,adminPassword:password});
       championsForm.reset();
       await loadChampions();
       inform(champions,response.message||'เก็บประวัติแชมป์แล้ว');
     }catch(error){inform(champions,error.message,true)}
     finally{
       el('#admin-champion-password',champions).value='';
       button.disabled=false;
     }
   });
   el('#admin-champion-archives',champions).addEventListener('click',async event=>{
     const button=event.target.closest('[data-confirm-champion]');
     if(!button)return;
     const id=Number(button.dataset.confirmChampion);
     // Admin must actively verify a pre-reset snapshot, not silently accept
     // an automatic archive created from possible test results.
     const password=window.prompt('กรุณากรอกรหัสผ่าน Admin เพื่อรับรองผลการแข่งขันจริงของชุดประวัตินี้');
     if(!password)return;
     const phrase=window.prompt('เมื่อยืนยันว่าไม่ใช่ผลทดสอบ ให้พิมพ์ ยืนยันแชมป์เก่า');
     if(phrase!=='ยืนยันแชมป์เก่า')return;
     button.disabled=true;
     try{
       const response=await api('admin_champions_confirm',
         {archiveId:id,confirmation:phrase,adminPassword:password});
       await loadChampions();
       inform(champions,response.message||'รับรองประวัติแล้ว');
     }catch(error){inform(champions,error.message,true)}
     finally{button.disabled=false}
   });

   // Two-stage confirmation keeps a checkbox selection from deleting records by accident.
   let resetPreviewToken='';
   let resetSelected=[];
   const resetConfirmation=el('#admin-reset-confirmation',reset);
   const resetPhrase=el('#admin-reset-phrase',reset);
   const resetExecute=el('#admin-reset-execute',reset);
   const resetPassword=el('#admin-reset-login-password',reset);
   function clearResetPreview(){
     resetPreviewToken='';
     resetSelected=[];
     resetPhrase.value='';
     resetPassword.value='';
     resetConfirmation.hidden=true;
     resetExecute.disabled=true;
     inform(reset,'');
   }
   reset.querySelectorAll('[data-reset-section]').forEach(c=>c.addEventListener('change',clearResetPreview));
   resetPassword.addEventListener('input',()=>{resetExecute.disabled=!(resetPreviewToken && resetPhrase.value==='ล้างข้อมูล' && resetPassword.value.length)});
   resetPhrase.addEventListener('input',()=>{
     resetExecute.disabled=!(resetPreviewToken && resetPhrase.value==='ล้างข้อมูล' && resetPassword.value.length);
   });
   el('#admin-reset-preview',reset).addEventListener('click',async ()=>{
     const selected=all('[data-reset-section]:checked',reset).map(c=>c.dataset.resetSection);
     if(!selected.length){inform(reset,'กรุณาเลือกหมวดที่ต้องการล้าง',true);return;}
     const btn=el('#admin-reset-preview',reset);
     btn.disabled=true;
     clearResetPreview();
     try{
       const data=await api('admin_reset_preview',{sections:selected});
       resetPreviewToken=String(data.token||'');
       resetSelected=selected;
       const summary='ตรวจสอบข้อมูลที่จะรีเซ็ต:\n'+selected.map(key=>{
         const name=resetSections.find(x=>x[0]===key)?.[1]||key;
         return '• '+name+' : '+(data.counts?.[key]??0)+' รายการ';
       }).join('\n')+'\n\n'+(data.notes||[]).join('\n')+
       '\n\nยืนยันภายใน 5 นาทีหลังการตรวจสอบ';
       el('#admin-reset-summary',reset).textContent=summary;
       resetConfirmation.hidden=false;
       resetExecute.disabled=true;
     }catch(error){inform(reset,error.message,true)}
     finally{btn.disabled=false}
   });
   resetExecute.addEventListener('click',async ()=>{
     if(!resetPreviewToken||resetPhrase.value!=='ล้างข้อมูล'||!resetPassword.value)return;
     if(!window.confirm('ยืนยันรีเซ็ตข้อมูลที่เลือกจริงหรือไม่?\nการทำรายการนี้ย้อนกลับไม่ได้'))return;
     const token=resetPreviewToken,selected=resetSelected.slice();
     // Invalidating the preview prevents double submission.
     resetPreviewToken='';
     resetExecute.disabled=true;
     try{
       const result=await api('admin_reset_execute',{sections:selected,token,confirmation:'ล้างข้อมูล',adminPassword:resetPassword.value});
       clearResetPreview();
       reset.querySelectorAll('[data-reset-section]').forEach(c=>c.checked=false);
       inform(reset,result.message||'รีเซ็ตข้อมูลสำเร็จ');
     }catch(error){
       resetPassword.value='';
       inform(reset,error.message+'\nกรุณาตรวจสอบรายการอีกครั้งก่อนลองใหม่',true);
       resetConfirmation.hidden=true;
     }
   });


   // Encrypted Backup: requires both the Admin login password and a separate archive passphrase.
   const exportForm=el('#admin-backup-export-form',backup);
   const restoreForm=el('#admin-backup-restore-form',backup);
   const restoreConfirm=el('#admin-backup-restore-confirm',backup);
   const restorePhrase=el('#admin-backup-confirm-text',backup);
   const restoreButton=el('#admin-backup-do-restore',backup);
   let restoreToken='';
   let restoreChecksumKey='';
   function archiveFormData(){
     const data=new FormData();
     const file=restoreForm.elements.backup.files[0];
     if(!file)throw new Error('กรุณาเลือกไฟล์ Backup');
     if(file.size>31457280)throw new Error('ไฟล์ Backup ต้องมีขนาดไม่เกิน 30 MB');
     data.append('backup',file);
     data.append('backupPassphrase',restoreForm.elements.backupPassphrase.value);
     data.append('adminPassword',restoreForm.elements.adminPassword.value);
     return data;
   }
   function clearRestorePreview(){
     restoreToken='';
     restoreChecksumKey='';
     restorePhrase.value='';
     restoreButton.disabled=true;
     restoreConfirm.hidden=true;
     inform(backup,'');
   }
   Array.from(restoreForm.elements).forEach(field=>field.addEventListener('change',clearRestorePreview));
   restorePhrase.addEventListener('input',()=>{
     restoreButton.disabled=!(restoreToken&&restorePhrase.value==='กู้คืนข้อมูล');
   });
   exportForm.addEventListener('submit',async function(e){
     e.preventDefault();
     const button=el('[type="submit"]',exportForm);
     button.disabled=true;
     try{
       const pass=exportForm.elements.backupPassphrase.value;
       if(pass.length<12)throw new Error('รหัสป้องกันไฟล์ต้องมีอย่างน้อย 12 ตัวอักษร');
       const blob=await window.bang1Bridge.adminDownload({
         adminPassword:exportForm.elements.adminPassword.value,
         backupPassphrase:pass
       });
       const objectUrl=URL.createObjectURL(blob);
       const link=document.createElement('a');
       const stamp=new Date().toISOString().slice(0,19).replace(/[-:T]/g,'');
       link.href=objectUrl;
       link.download='bang1-backup-'+stamp+'.bnwbackup';
       document.body.appendChild(link);
       link.click();
       link.remove();
       setTimeout(()=>URL.revokeObjectURL(objectUrl),30000);
       inform(backup,'ดาวน์โหลด Backup แบบเข้ารหัสแล้ว กรุณาเก็บรหัสไฟล์ไว้ในที่ปลอดภัย');
     }catch(error){inform(backup,error.message,true)}
     finally{
       exportForm.elements.adminPassword.value='';
       exportForm.elements.backupPassphrase.value='';
       button.disabled=false;
     }
   });
   el('#admin-backup-check',backup).addEventListener('click',async function(){
     this.disabled=true;
     clearRestorePreview();
     try{
       const data=await api('admin_backup_preview',archiveFormData());
       restoreToken=String(data.token||'');
       restoreChecksumKey=restoreForm.elements.backup.files[0]?.name||'';
       const counts=Object.entries(data.counts||{}).map(([table,n])=>'• '+table+' : '+n+' แถว').join('\n');
       el('#admin-backup-restore-summary',backup).textContent=
         'Backup เมื่อ: '+(data.createdAt||'ไม่ระบุ')+'\n'+counts+'\n\n'+(data.note||'')+
         '\n'+(data.driveNote||'')+'\n\nโปรดยืนยันภายใน 5 นาที';
       restoreConfirm.hidden=false;
     }catch(error){inform(backup,error.message,true)}
     finally{this.disabled=false}
   });
   restoreButton.addEventListener('click',async function(){
     if(!restoreToken||restorePhrase.value!=='กู้คืนข้อมูล')return;
     if(!window.confirm('ยืนยันกู้คืนจากไฟล์ Backup จริงหรือไม่?\nระบบจะแทนที่ข้อมูลปัจจุบัน และไม่สามารถย้อนกลับได้'))return;
     this.disabled=true;
     const token=restoreToken;
     restoreToken='';
     try{
       const data=archiveFormData();
       data.append('token',token);
       data.append('confirmation','กู้คืนข้อมูล');
       const result=await api('admin_backup_restore',data);
       clearRestorePreview();
       restoreForm.reset();
       inform(backup,result.message||'กู้คืนข้อมูลสำเร็จ');
     }catch(error){
       restoreConfirm.hidden=true;
       inform(backup,error.message+' กรุณาตรวจสอบไฟล์ใหม่ก่อนกู้คืน',true);
     }finally{
       restoreForm.elements.adminPassword.value='';
       restoreForm.elements.backupPassphrase.value='';
       this.disabled=false;
     }
   });

   var currentLogPage=1;
   async function loadLogs(pageNo){
     var rows=el('[data-admin-rows="logs"]');
     rows.innerHTML='<tr><td colspan="15">กำลังโหลด...</td></tr>';
     var q=el('#admin-logs-search').value.trim();
     var result=await api('admin_logs&page='+encodeURIComponent(pageNo)+'&q='+encodeURIComponent(q));
     currentLogPage=result.page||1;
     var cols=['event_time','action_code','action_text','module','target_type','target_id','target_name','runner_id','reg_id','event_name','user_email','user_name','user_role','user_school','detail'];
     rows.innerHTML=(result.data||[]).map(function(x){
       return '<tr>'+cols.map(function(k){return '<td>'+escapeText(x[k])+'</td>'}).join('')+'</tr>';
     }).join('')||'<tr><td colspan="15">ไม่พบรายการ</td></tr>';
     el('[data-logs-position]',logs).textContent='หน้า '+currentLogPage+' / '+Math.max(1,Math.ceil((result.total||0)/20))+' (ทั้งหมด '+result.total+' รายการ)';
     el('[data-logs-prev]',logs).disabled=currentLogPage<=1;
     el('[data-logs-next]',logs).disabled=currentLogPage*20>=result.total;
     inform(logs,'');
   }
   el('#admin-logs-find').addEventListener('click',function(){loadLogs(1).catch(e=>inform(logs,e.message,true))});
   el('#admin-logs-search').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();loadLogs(1).catch(e=>inform(logs,e.message,true))}});
   el('[data-logs-prev]',logs).onclick=function(){loadLogs(currentLogPage-1).catch(e=>inform(logs,e.message,true))};
   el('[data-logs-next]',logs).onclick=function(){loadLogs(currentLogPage+1).catch(e=>inform(logs,e.message,true))};
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);
 else initialize();
})();