/* Admin-only race event management. Keeps original schedule/table and stylesheet. */
(function(){
'use strict';
const $=(selector,base=document)=>(base||document).querySelector(selector);
const htmlEscape=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
let loadedEvents=[];
let manageVisible=false;
function actor(){return typeof currentUser==='undefined'?null:currentUser}
function isAdmin(){const user=actor();return !!(user&&['Admin','TopAdmin'].includes(user.role))}
function notice(message,isError=false){
  const node=$('#bang1EventManagementMessage');
  if(node){node.textContent=message||'';node.style.color=isError?'#fca5a5':'#a7f3d0'}
}
function createUI(){
  const page=$('#page-schedule');
  if(!page||$('#bang1EventManager'))return;
  const header=$('.table-header-right',page);
  if(!header)return;
  const buttons=document.createElement('span');
  buttons.id='bang1EventControls';
  buttons.className='btn-row';
  buttons.hidden=true;
  buttons.innerHTML='<button type="button" class="btn btn-outline" id="bang1EventManagerToggle">จัดการรายการแข่งขัน</button>'+
    '<button type="button" class="btn btn-primary" id="bang1EventsDraw">สุ่มฮีทและลู่</button>';
  header.prepend(buttons);
  const panel=document.createElement('div');
  panel.id='bang1EventManager';
  panel.className='card';
  panel.hidden=true;
  panel.innerHTML='<div class="card-header"><div class="card-title">จัดการรายการแข่งขัน</div></div>'+
    '<p>เพิ่ม แก้ไข หรือลบรายการแข่งขัน ข้อมูลรอบคัดเลือกและรอบชิงที่ตั้งไว้เดิมจะคงอยู่จนกว่ารายการจะเข้าเกณฑ์ตามจำนวนโรงเรียนที่สมัคร</p>'+
    '<form id="bang1EventForm" autocomplete="off">'+
    '<input name="id" type="hidden">'+
    '<div class="form-grid">'+
    '<div class="field"><label>ชื่อรายการแข่งขัน</label><input class="input" name="name" required maxlength="255"></div>'+
    '<div class="field"><label>ประเภท</label><input class="input" name="category" maxlength="80"></div>'+
    '<div class="field"><label>ระดับ</label><input class="input" name="level" maxlength="60"></div>'+
    '<div class="field"><label>เพศ</label><input class="input" name="gender" maxlength="30"></div>'+
    '<div class="field"><label>หมายเหตุ / รอบที่ตั้งไว้</label><select class="input" name="remark"><option value="">-- ไม่ระบุ --</option>'+
    '<option value="คัดเลือก">คัดเลือก</option><option value="ชิงชนะเลิศ">ชิงชนะเลิศ</option></select></div>'+
    '<div class="field"><label>ฮีท</label><input class="input" name="heat" maxlength="50"></div>'+
    '<div class="field"><label>กำหนดฮีท</label><input class="input" name="heatPlan" maxlength="50"></div>'+
    '<div class="field"><label>รอบการแข่งขัน</label><input class="input" name="stage" maxlength="100"></div>'+
    '</div><div class="btn-row"><button class="btn btn-primary" type="submit">บันทึกรายการแข่งขัน</button>'+
    '<button type="button" class="btn btn-outline" id="bang1NewEvent">เพิ่มรายการใหม่ / ล้างฟอร์ม</button></div></form>'+
    '<p class="bang1-event-note">เมื่อแก้ชื่อรายการ ประเภท ระดับ หรือเพศ ระบบจะอัปเดตแถวที่เชื่อมกัน รวมถึงใบสมัคร ฮีท และชื่อรายการในเกียรติบัตรที่เกี่ยวข้อง</p>'+
    '<div class="btn-row" style="margin-top:10px"><input id="bang1EventSearch" class="input" type="search" placeholder="ค้นหา EventID หรือชื่อรายการ">'+
    '<button type="button" class="btn btn-outline" id="bang1EventRefresh">รีเฟรช</button></div>'+
    '<div class="table-wrap"><div class="table-scroller"><table><thead><tr>'+
    '<th>EventID</th><th>รายการวิ่ง</th><th>ประเภท / ระดับ / เพศ</th><th>รอบที่ตั้งไว้</th><th>ฮีท</th><th>โรงเรียนที่สมัคร</th><th>จัดการ</th>'+
    '</tr></thead><tbody id="bang1EventTable"></tbody></table></div></div>'+
    '<p id="bang1EventManagementMessage" aria-live="polite"></p>';
  page.appendChild(panel);
  const form=$('#bang1EventForm');
  const clear=()=>{form.reset();form.elements.id.value='';notice('')};
  $('#bang1NewEvent').addEventListener('click',clear);
  $('#bang1EventSearch').addEventListener('input',renderTable);
  $('#bang1EventRefresh').addEventListener('click',()=>loadEvents());
  $('#bang1EventManagerToggle').addEventListener('click',()=>{
    if(!isAdmin())return;
    manageVisible=!manageVisible;
    panel.hidden=!manageVisible;
    $('#bang1EventManagerToggle').textContent=manageVisible?'ปิดจัดการรายการแข่งขัน':'จัดการรายการแข่งขัน';
    if(manageVisible)loadEvents();
  });
  $('#bang1EventsDraw').addEventListener('click',async()=>{
    if(!isAdmin())return;
    const approved=confirm('ยืนยันสุ่มฮีทและลู่วิ่งใหม่ให้ทุกรายการแข่งขัน?\nการสุ่มจะเขียนทับฮีท/ลู่เดิมของใบสมัครทั้งหมด');
    if(!approved)return;
    const button=$('#bang1EventsDraw');button.disabled=true;
    try {
      const result=await window.bang1Bridge.adminRequest('auto_heats',{});
      notice((result.message||'สุ่มฮีทและลู่สำเร็จ')+' ('+(result.heatsCount||0)+' ฮีท)');
      if(typeof loadSchedule==='function')loadSchedule();
      if(manageVisible)await loadEvents();
    }catch(err){notice(err.message,true)}
    finally{button.disabled=false}
  });
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!isAdmin())return;
    const btn=$('[type="submit"]',form);btn.disabled=true;
    const fields=['id','name','category','level','gender','remark','heat','heatPlan','stage'];
    const payload={};
    for(const key of fields)payload[key]=form.elements[key].value.trim();
    try{
      const result=await window.bang1Bridge.adminRequest('manage_event_save',payload);
      clear();
      await loadEvents();
      if(typeof loadSchedule==='function')loadSchedule();
      notice(result.message||'บันทึกรายการแข่งขันสำเร็จ');
    }catch(err){notice(err.message,true)}
    finally{btn.disabled=false}
  });
  $('#bang1EventTable').addEventListener('click',async event=>{
    const edit=event.target.closest('[data-event-edit]');
    const del=event.target.closest('[data-event-delete]');
    const id=edit?.dataset.eventEdit||del?.dataset.eventDelete;
    const item=loadedEvents.find(x=>String(x.id)===String(id));
    if(!item||!isAdmin())return;
    if(edit){
      for(const key of ['id','name','category','level','gender','remark','heat','heatPlan','stage']){
        const input=form.elements[key];
        if(input)input.value=item[key]||'';
      }
      // Support imported legacy remark labels beyond the two standard options.
      if(item.remark && form.elements.remark.value!==item.remark){
        const option=document.createElement('option');
        option.value=item.remark;option.textContent=item.remark;
        form.elements.remark.appendChild(option);
        form.elements.remark.value=item.remark;
      }
      form.scrollIntoView({behavior:'smooth',block:'start'});
      return;
    }
    const count=Number(item.registrationCount||0);
    let confirmation='';
    if(count>0){
      const allowed=confirm('คำเตือน: รายการ '+item.name+' มีข้อมูลการสมัครแล้ว '+count+' รายการ\n'+
        'หากยังมีฮีทหรือรอบชิงรายการเดียวกัน ระบบจะเก็บใบสมัครไว้\n'+
        'หากเป็นแถวสุดท้ายของรายการ ใบสมัครของรายการนั้นจะถูกลบด้วย\n\nต้องการดำเนินการต่อหรือไม่?');
      if(!allowed)return;
      confirmation=prompt('เพื่อยืนยันการลบรายการที่มีใบสมัคร กรุณาพิมพ์: ลบรายการและใบสมัคร')||'';
      if(confirmation!=='ลบรายการและใบสมัคร')return;
    }else if(!confirm('ยืนยันลบรายการแข่งขัน #'+id+' '+item.name+' ?'))return;
    del.disabled=true;
    try{
      const result=await window.bang1Bridge.adminRequest('manage_event_delete',{id:item.id,confirmation});
      await loadEvents();
      if(typeof loadSchedule==='function')loadSchedule();
      notice(result.message+(result.keptRegistrations?' (เก็บข้อมูลใบสมัครเดิมไว้)':''));
    }catch(err){notice(err.message,true);del.disabled=false}
  });
  function refreshVisibility(){
    const shown=isAdmin();
    buttons.hidden=!shown;
    buttons.style.display=shown?'':'none';
    if(!shown){manageVisible=false;panel.hidden=true}
  }
  refreshVisibility();
  window.addEventListener('bang1:login',()=>setTimeout(refreshVisibility,0));
  document.querySelector('[data-page="schedule"]')?.addEventListener('click',refreshVisibility);
  async function loadEvents(){
    const tbody=$('#bang1EventTable');
    tbody.innerHTML='<tr><td colspan="7">กำลังโหลด...</td></tr>';
    try{
      const result=await window.bang1Bridge.adminRequest('manage_events');
      loadedEvents=Array.isArray(result.data)?result.data:[];
      renderTable();
    }catch(err){tbody.innerHTML='<tr><td colspan="7">โหลดรายการไม่สำเร็จ</td></tr>';notice(err.message,true)}
  }
  function renderTable(){
    const tbody=$('#bang1EventTable');
    if(!tbody)return;
    const term=String($('#bang1EventSearch').value||'').trim().toLowerCase();
    const visible=loadedEvents.filter(row=>!term||String(row.id).includes(term)||
      String(row.name||'').toLowerCase().includes(term));
    tbody.innerHTML=visible.map(row=>'<tr>'+
      '<td>'+htmlEscape(row.id)+'</td><td>'+htmlEscape(row.name)+'</td>'+
      '<td>'+htmlEscape([row.category,row.level,row.gender].filter(Boolean).join(' • '))+'</td>'+
      '<td>'+htmlEscape(row.remark||row.stage||'')+'</td><td>'+htmlEscape(row.heat)+'</td>'+
      '<td>'+htmlEscape(row.schoolCount)+'</td><td>'+
      '<button class="btn btn-outline btn-xs" type="button" data-event-edit="'+htmlEscape(row.id)+'">แก้ไข</button> '+
      '<button class="btn btn-outline btn-xs" type="button" data-event-delete="'+htmlEscape(row.id)+'">ลบ</button></td></tr>').join('')||
      '<tr><td colspan="7">ไม่มีรายการแข่งขัน</td></tr>';
  }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',createUI);
else createUI();
})();
