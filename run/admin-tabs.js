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
   var names=[['check','ตรวจสอบนักวิ่ง'],['settings','ตั้งค่าเปิด–ปิดระบบ'],['schools','โรงเรียน'],['coaches','ผู้ฝึกซ้อม'],['logs','ประวัติการใช้งาน']];
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
   var schools=createPanel('schools','โรงเรียน',['SchoolID','SchoolName','schoolnickname','Active','บันทึก']);
   var coaches=createPanel('coaches','ผู้ฝึกซ้อม',['CoachID','Name','Email','SchoolName','Role','รหัสผ่าน','จัดการ'],
     '<form id="admin-coach-form" autocomplete="off">'+
       '<input name="id" type="hidden">'+
       '<div class="form-grid">'+
         '<div class="field"><label>ชื่อผู้ฝึกซ้อม</label><input name="name" class="input" required></div>'+
         '<div class="field"><label>Email</label><input name="email" type="email" class="input" required></div>'+
         '<div class="field"><label>SchoolName</label><select name="schoolName" class="input" required></select></div>'+
         '<div class="field"><label>Role</label><select name="role" class="input"><option value="Admin">Admin</option><option value="Manager">Manager</option><option value="Teacher">Teacher</option></select></div>'+
         '<div class="field"><label>รหัสผ่านใหม่ (ไม่กรอกหากไม่เปลี่ยน)</label><input name="password" type="password" class="input" autocomplete="new-password" minlength="8"></div>'+
       '</div>'+
       '<div class="btn-row"><button class="btn btn-primary" type="submit">บันทึกผู้ฝึกซ้อม</button>'+
       '<button class="btn btn-outline" type="reset">เพิ่มผู้ฝึกซ้อมใหม่ / ล้างฟอร์ม</button></div>'+
     '</form>');
   var logs=createPanel('logs','ประวัติการใช้งาน',
      ['Timestamp','ActionCode','ActionTextTH','Module','TargetType','TargetId','TargetName','RunnerID','RegID','EventName','UserEmail','UserName','UserRole','UserSchool','Detail'],
      '<div class="btn-row"><input id="admin-logs-search" class="input" type="search" placeholder="ค้นหาประวัติการใช้งาน">'+
      '<button id="admin-logs-find" type="button" class="btn btn-outline">ค้นหา</button></div>');
   [settings,schools,coaches,logs].forEach(function(p){page.appendChild(p)});

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

   async function loadSchools(){
     var rows=el('[data-admin-rows="schools"]');
     rows.innerHTML='<tr><td colspan="5">กำลังโหลด...</td></tr>';
     var r=await api('admin_schools');
     var options=['Active','Inactive'];
     rows.innerHTML=(r.data||[]).map(function(x){
       return '<tr data-school-id="'+escapeText(x.id)+'"><td>'+escapeText(x.id)+'</td>'+
         '<td><input class="input" data-school-name value="'+escapeText(x.name)+'"></td>'+
         '<td><input class="input" data-school-nickname value="'+escapeText(x.nickname)+'"></td>'+
         '<td><select class="input" data-school-active>'+options.map(function(s){return '<option'+(x.active===s?' selected':'')+'>'+s+'</option>'}).join('')+'</select></td>'+
         '<td><button type="button" class="btn btn-primary btn-xs" data-save-school>บันทึก</button></td></tr>';
     }).join('')+'<tr data-school-id="0"><td>ใหม่</td>'+
       '<td><input class="input" data-school-name placeholder="SchoolName"></td>'+
       '<td><input class="input" data-school-nickname placeholder="schoolnickname"></td>'+
       '<td><select class="input" data-school-active><option>Active</option><option>Inactive</option></select></td>'+
       '<td><button type="button" class="btn btn-primary btn-xs" data-save-school>เพิ่ม</button></td></tr>';
     inform(schools,'');
   }
   schools.addEventListener('click',async function(e){
     var b=e.target.closest('[data-save-school]');if(!b)return;
     var tr=b.closest('tr');
     var payload={id:tr.dataset.schoolId,name:el('[data-school-name]',tr).value,
       nickname:el('[data-school-nickname]',tr).value,active:el('[data-school-active]',tr).value};
     b.disabled=true;
     try{var r=await api('admin_school_save',payload);await loadSchools();inform(schools,r.message||'บันทึกสำเร็จ')}
     catch(err){inform(schools,err.message,true)}
     finally{b.disabled=false}
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
         '<td><button type="button" data-reset-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs">ตั้งรหัสผ่านใหม่</button></td>'+
         '<td><button type="button" data-edit-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs">แก้ไข</button> '+
         '<button type="button" data-delete-coach="'+escapeText(x.id)+'" class="btn btn-outline btn-xs"'+
         (own?' disabled title="ไม่สามารถลบบัญชีตนเอง"':'')+'>ลบ</button></td></tr>';
     }).join('')||'<tr><td colspan="7">ไม่พบผู้ฝึกซ้อมในโรงเรียนที่เลือก</td></tr>';
   }
   el('#admin-coach-school-filter',coaches).addEventListener('change',renderCoachRows);
   async function loadCoaches(){
     var rows=el('[data-admin-rows="coaches"]');
     rows.innerHTML='<tr><td colspan="7">กำลังโหลด...</td></tr>';
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