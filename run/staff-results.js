/* Mobile-first Staff scoring, only for assigned competition fixtures. */
(function(){
'use strict';
const $=(selector,root=document)=>root.querySelector(selector);
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
let fixtures=[],tab='all',selected=null,loading=false,submitting=false,previousFocus=null;
function isStaff(){return typeof currentUser!=='undefined' && currentUser?.role==='Staff'}
function request(action,data){return window.bang1Bridge.adminRequest(action,data)}
function status(message,error=false){
  const node=$('#bang1StaffResultNotice');
  if(node){node.textContent=message||'';node.style.color=error?'#fca5a5':'#a7f3d0'}
}
function ensurePage(){
  const page=$('#page-results');
  if(!page||$('#bang1StaffResults'))return;
  const app=document.createElement('section');
  app.id='bang1StaffResults';
  app.hidden=true;
  app.innerHTML='<div class="card"><div class="card-title">บันทึกผลการแข่งขัน</div>'+
    '<div class="bang1-staff-filters" role="group" aria-label="กรองสถานะบันทึกผล">'+
    '<button type="button" class="btn btn-outline active" data-result-tab="all">ผลทั้งหมด (0)</button>'+
    '<button type="button" class="btn btn-outline" data-result-tab="saved">บันทึกผลแล้ว (0)</button>'+
    '<button type="button" class="btn btn-outline" data-result-tab="unsaved">ยังไม่บันทึกผล (0)</button></div>'+
    '<div id="bang1StaffResultNotice" role="status" aria-live="polite"></div>'+
    '<div id="bang1StaffResultList" class="bang1-staff-result-list"></div></div>';
  const oldCard=page.querySelector('.card');
  if(oldCard)oldCard.id='bang1ResultsReadOnly';
  page.appendChild(app);
  app.querySelectorAll('[data-result-tab]').forEach(button=>{
    button.addEventListener('click',()=>{tab=button.dataset.resultTab;drawList()});
  });
  $('#bang1StaffResultList',app).addEventListener('click',event=>{
    const button=event.target.closest('[data-fixture]');
    if(!button)return;
    const item=fixtures.find(x=>x.fixtureKey===button.dataset.fixture);
    if(item)openDialog(item);
  });

  const modal=document.createElement('div');
  modal.id='bang1StaffScoreBackdrop';
  modal.hidden=true;
  modal.innerHTML='<div id="bang1StaffScoreDialog" role="dialog" aria-modal="true" aria-labelledby="bang1StaffScoreTitle">'+
    '<header class="bang1-score-head"><div><h2 id="bang1StaffScoreTitle"></h2>'+
    '<p id="bang1StaffScoreSubtitle"></p></div><button type="button" class="bang1-score-close" aria-label="ปิดหน้าต่าง" id="bang1ScoreClose">×</button></header>'+
    '<div class="bang1-score-body"><p id="bang1ScoreHint"></p><div id="bang1ScoreFields"></div></div>'+
    '<footer class="bang1-score-foot"><div id="bang1ScoreNotice" role="status" aria-live="polite"></div>'+
    '<button type="button" class="btn btn-outline" id="bang1ScoreCancel">ยกเลิก</button>'+
    '<button type="button" class="btn btn-primary" id="bang1ScoreSave">บันทึกผลการแข่งขัน</button></footer></div>';
  document.body.appendChild(modal);
  $('#bang1ScoreClose').addEventListener('click',closeDialog);
  $('#bang1ScoreCancel').addEventListener('click',closeDialog);
  modal.addEventListener('click',event=>{if(event.target===modal)closeDialog()});
  document.addEventListener('keydown',event=>{
    if(modal.hidden)return;
    if(event.key==='Escape'&&!submitting){event.preventDefault();closeDialog()}
    if(event.key==='Tab'){
      const focusable=[...modal.querySelectorAll('button:not(:disabled),select:not(:disabled)')];
      const first=focusable[0],last=focusable[focusable.length-1];
      if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
      else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
    }
  });
  $('#bang1ScoreSave').addEventListener('click',save);
}
function refreshPageVisibility(){
  ensurePage();
  const app=$('#bang1StaffResults');
  if(!app)return;
  const staff=isStaff();
  app.hidden=!staff;
  const original=$('#bang1ResultsReadOnly');
  if(original)original.hidden=staff;
  if(!staff&&!$('#bang1StaffScoreBackdrop')?.hidden)closeDialog();
}
async function load(){
  refreshPageVisibility();
  if(!isStaff()||loading)return;
  loading=true;
  status('กำลังโหลดรายการแข่งขัน...');
  try{
    const result=await request('staff_results_list');
    fixtures=Array.isArray(result.data)?result.data:[];
    status('');
    drawList();
  }catch(e){status(e.message||'ไม่สามารถโหลดรายการแข่งขัน',true)}
  finally{loading=false}
}
function drawList(){
  const root=$('#bang1StaffResults');if(!root||!isStaff())return;
  const counts={all:fixtures.length,saved:fixtures.filter(f=>f.isSaved).length,
    unsaved:fixtures.filter(f=>!f.isSaved).length};
  root.querySelectorAll('[data-result-tab]').forEach(button=>{
    const key=button.dataset.resultTab;
    const names={all:'ผลทั้งหมด',saved:'บันทึกผลแล้ว',unsaved:'ยังไม่บันทึกผล'};
    button.textContent=names[key]+' ('+counts[key]+')';
    button.classList.toggle('active',key===tab);
    button.setAttribute('aria-pressed',key===tab?'true':'false');
  });
  const visible=fixtures.filter(f=>tab==='all'||(tab==='saved'?f.isSaved:!f.isSaved));
  const list=$('#bang1StaffResultList');
  list.innerHTML=visible.map(f=>'<button type="button" class="bang1-staff-fixture'+
    (f.round==='ชิงชนะเลิศ'?' bang1-staff-final':'')+
    '" data-fixture="'+esc(f.fixtureKey)+'">'+
    '<span class="bang1-fixture-main"><strong>'+esc(f.eventName)+'</strong>'+
    '<small>'+esc(f.round)+' • ฮีท '+esc(f.heat)+' • '+esc(f.registeredSchools)+' โรงเรียน</small></span>'+
    '<span class="bang1-fixture-right">'+
    '<span class="bang1-fixture-state '+(f.isSaved?'saved':'pending')+'">'+
    (f.isSaved?'บันทึกผลแล้ว':(f.pendingDraw?'รอจัดฮีท':'ยังไม่บันทึก'))+'</span><span aria-hidden="true">›</span></span></button>').join('')||
    '<p class="bang1-empty-fixtures">ไม่มีรายการแข่งขันในสถานะนี้</p>';
}
function openDialog(item){
  if(!isStaff())return;
  selected=item;
  previousFocus=document.activeElement;
  const modal=$('#bang1StaffScoreBackdrop');
  $('#bang1StaffScoreTitle').textContent=item.eventName;
  $('#bang1StaffScoreSubtitle').textContent=item.round+' • ฮีท '+item.heat+
    ' • '+item.registeredSchools+' โรงเรียนสมัคร';
  const hint=$('#bang1ScoreHint');
  hint.textContent=item.pendingDraw
    ?'ยังไม่มีการสุ่มฮีทและลู่สำหรับรายการนี้ กรุณาให้ Admin จัดฮีทก่อน'
    :'เลือกโรงเรียนตามอันดับ โดยโรงเรียนที่เลือกแล้วจะไม่สามารถเลือกซ้ำในอันดับอื่นได้';
  const fields=$('#bang1ScoreFields');
  fields.replaceChildren();
  for(let rank=1;rank<=8;rank++){
    const row=document.createElement('label');
    row.className='bang1-score-row';
    const name=document.createElement('span');name.textContent='อันดับ '+rank;
    const select=document.createElement('select');
    select.className='input';
    select.dataset.rank=String(rank);
    select.disabled=item.pendingDraw||!item.schools.length;
    const blank=new Option('-- เลือกโรงเรียน --','');
    select.add(blank);
    for(const school of item.schools)select.add(new Option(school,school));
    select.value=item.ranks?.[String(rank)]||'';
    select.addEventListener('change',updateOptions);
    row.append(name,select);
    fields.appendChild(row);
  }
  $('#bang1ScoreNotice').textContent='';
  $('#bang1ScoreSave').disabled=item.pendingDraw||!item.schools.length;
  updateOptions();
  modal.hidden=false;
  document.body.classList.add('bang1-score-modal-open');
  $('#bang1ScoreClose').focus();
}
function closeDialog(){
  if(submitting)return;
  const modal=$('#bang1StaffScoreBackdrop');
  if(!modal)return;
  modal.hidden=true;selected=null;
  document.body.classList.remove('bang1-score-modal-open');
  previousFocus?.focus?.();
}
function updateOptions(){
  const selects=[...document.querySelectorAll('#bang1ScoreFields select')];
  const chosen=new Set(selects.map(select=>select.value).filter(Boolean));
  // Rebuild the menus so a selected school truly disappears from all other
  // dropdowns (rather than merely becoming disabled).
  for(const select of selects){
    const current=select.value;
    select.replaceChildren(new Option('-- เลือกโรงเรียน --',''));
    for(const school of selected?.schools||[]){
      if(!chosen.has(school)||school===current){
        select.add(new Option(school,school));
      }
    }
    select.value=current;
  }
}
async function save(){
  if(!isStaff()||!selected||submitting||selected.pendingDraw)return;
  const ranks={};
  for(const select of document.querySelectorAll('#bang1ScoreFields select')){
    if(select.value)ranks[select.dataset.rank]=select.value;
  }
  const values=Object.values(ranks);
  const message=$('#bang1ScoreNotice');
  if(!values.length){message.textContent='กรุณาเลือกโรงเรียนอย่างน้อยหนึ่งอันดับ';return}
  if(new Set(values).size!==values.length){
    message.textContent='ไม่สามารถเลือกโรงเรียนซ้ำระหว่างอันดับได้';return;
  }
  submitting=true;
  const saveButton=$('#bang1ScoreSave');
  saveButton.disabled=true;
  message.textContent='กำลังบันทึก...';
  try{
    const result=await request('staff_results_save',{
      eventId:selected.eventId,round:selected.round,heat:selected.heat,ranks
    });
    message.textContent=result.message||'บันทึกผลแล้ว';
    submitting=false;
    closeDialog();
    await load();
  }catch(e){
    submitting=false;
    message.textContent=e.message||'บันทึกไม่สำเร็จ';
    saveButton.disabled=false;
  }
}
function init(){
  ensurePage();
  refreshPageVisibility();
  const button=document.querySelector('.menu button[data-page="results"]');
  button?.addEventListener('click',()=>setTimeout(load,0));
  window.addEventListener('bang1:login',()=>setTimeout(refreshPageVisibility,0));
}
window.refreshStaffResults=load;
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
