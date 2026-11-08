'use strict';
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
const state={user:null,csrf:'',page:'login',runners:[],events:[],regs:[],schedule:[],runnersAll:[],filter:'all',uploadId:null,uploadType:null};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const link=s=>typeof s==='string'&&/^https:\/\//i.test(s)?esc(s):'';

/** Turn existing Google Drive viewer/download URLs into an image thumbnail URL. */
function drivePhotoFileId(value) {
 const raw=String(value??'').trim();
 let url;try{url=new URL(raw)}catch{return ''}
 if(url.protocol!=='https:'||!['drive.google.com','drive.usercontent.google.com'].includes(url.hostname.toLowerCase()))return '';
 const match=url.pathname.match(/\/(?:file\/d|d)\/([A-Za-z0-9_-]{15,})(?:\/|$)/);
 const id=url.searchParams.get('id')||(match&&match[1])||'';
 return /^[A-Za-z0-9_-]{15,}$/.test(id)?id:'';
}
function photoUrl(value,size=320){
 const raw=String(value??'').trim();
 let url;try{url=new URL(raw)}catch{return ''}
 if(url.protocol!=='https:')return '';
 const fileId=drivePhotoFileId(raw);
 return fileId?'https://drive.google.com/thumbnail?id='+encodeURIComponent(fileId)+'&sz=w'+size:url.href;
}
function avatarPhoto(r){
 const src=photoUrl(r.Photo,320);
 const id=drivePhotoFileId(r.Photo);
 const fallback=String(r.FirstName||'?').trim().charAt(0)||'?';
 return '<span class="runner-avatar">'+(src?'<img data-runner-photo data-drive-id="'+esc(id)+'" src="'+esc(src)+'" alt="" loading="lazy" referrerpolicy="no-referrer">':'')+
 '<span class="avatar-fallback" '+(src?'hidden':'')+'>'+esc(fallback)+'</span></span>';
}
// If thumbnail is inaccessible try another Google image endpoint and then initials.
document.addEventListener('error',event=>{
 const img=event.target;
 if(!(img instanceof HTMLImageElement)||!img.hasAttribute('data-runner-photo'))return;
 const id=img.dataset.driveId||'';
 if(id&&!img.dataset.triedFallback){
   img.dataset.triedFallback='1';
   img.src='https://lh3.googleusercontent.com/d/'+encodeURIComponent(id)+'=w320';
   return;
 }
 img.hidden=true;
 const placeholder=img.nextElementSibling;
 if(placeholder&&placeholder.classList.contains('avatar-fallback'))placeholder.hidden=false;
},true);

const msg=(s,isError=false)=>{const el=$('#toast');el.textContent=s;el.style.background=isError?'#991b1b':'#155e75';el.style.display='block';clearTimeout(msg.timer);msg.timer=setTimeout(()=>el.style.display='none',4500)};
async function api(action,data,form=false){
 const options={method:data==null?'GET':'POST',credentials:'same-origin',headers:{}};
 if(data!=null){if(state.csrf)options.headers['X-CSRF-Token']=state.csrf;if(form){options.body=data;}else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(data)}}
 const r=await fetch('api.php?action='+encodeURIComponent(action),options);
 let result;try{result=await r.json()}catch{throw Error('เซิร์ฟเวอร์ส่งข้อมูลผิดรูปแบบ ('+r.status+')')}
 if(!r.ok||result.success===false)throw Error(result.message||'เซิร์ฟเวอร์ไม่สามารถทำรายการได้');
 return result;
}
function admin(){return ['Admin','TopAdmin'].includes(state.user?.role)}
function navigate(page){
 if(!state.user&&page!=='login')page='login';
 if(['admin','logs'].includes(page)&&!admin())return;
 state.page=page;
 $$('.page').forEach(e=>e.classList.toggle('active',e.id==='page-'+page));
 $$('#menu button').forEach(e=>e.classList.toggle('active',e.dataset.page===page));
 if(page==='runners')loadRunners();
 if(page==='register')loadRegister();
 if(page==='schedule')loadSchedule();
 if(page==='print')loadPrint();
 if(page==='admin')loadAdmin();
 if(page==='logs')loadLogs();
 if(page==='certificates')loadCertificates();
 if(page==='results')loadResults();
}
async function guarded(fn){try{await fn()}catch(e){msg(e.message||'ผิดพลาด',true);console.error(e)}}
async function loadUser(){
 const r=await api('me');
 state.user=r.user;state.csrf=r.csrf||'';
 $('body').classList.toggle('admin',admin());
 $('#userbox').textContent=state.user?state.user.name+' · '+state.user.schoolName+' · '+state.user.role:'ยังไม่ได้เข้าสู่ระบบ';
 if(state.user){$('#regSchool').value=state.user.schoolName;navigate('runners')}else navigate('login');
}
$('#loginForm').addEventListener('submit',e=>{e.preventDefault();guarded(async()=>{
 const email=$('#loginEmail').value.trim(),password=$('#loginPassword').value;
 const r=await api('login',{email,password});
 $('#loginPassword').value='';state.user=r.user;state.csrf=r.csrf;
 $('body').classList.toggle('admin',admin());$('#userbox').textContent=r.user.name+' · '+r.user.schoolName+' · '+r.user.role;$('#regSchool').value=r.user.schoolName;
 msg('เข้าสู่ระบบสำเร็จ');navigate('runners');
})});
$('#logout').onclick=()=>guarded(async()=>{await api('logout',{});state.user=null;state.csrf='';$('body').classList.remove('admin');navigate('login');});
$$('#menu button').forEach(b=>b.addEventListener('click',()=>navigate(b.dataset.page)));
const pill=s=>'<span class="pill '+(s==='ผ่านการตรวจสอบ'?'good':s==='ไม่ผ่านการตรวจสอบ'?'bad':'')+'">'+esc(s||'รอตรวจสอบ')+'</span>';
const initials=r=>esc((r.FirstName||'').slice(0,1));
async function loadRunners(){
 const data=await api('runners');state.runners=data.data||[];renderRunners();
}
function renderRunners(){
 const q=$('#runnerSearch').value.trim().toLowerCase(),f=state.filter;
 const data=state.runners.filter(r=>(f==='all'||r.Gender===f||r.Status===f)&&[r.FirstName,r.LastName,r.RunnerID].join(' ').toLowerCase().includes(q));
 $('#runnersBody').innerHTML=data.length?data.map((r,i)=>`<tr data-runner="${esc(r.RunnerID)}"><td>${i+1}</td><td>${avatarPhoto(r)}${esc(r.FirstName)} ${esc(r.LastName)}</td><td>${esc(r.Level)}</td><td>${esc(r.Gender)}</td><td>${esc(r.Age)}</td><td>${pill(r.Status)}</td><td><button type="button" class="secondary" data-upload="${esc(r.RunnerID)}:photo" ${r.Status==='ผ่านการตรวจสอบ'?'disabled':''}>${r.Photo?'📷 แก้ไข':'📷 แนบรูป'}</button></td><td><button type="button" class="secondary" data-upload="${esc(r.RunnerID)}:idproof" ${r.Status==='ผ่านการตรวจสอบ'?'disabled':''}>${r.IDProofURL?'📎 แก้ไข':'📎 แนบหลักฐาน'}</button></td></tr>`).join(''):'<tr><td colspan="8">ยังไม่มีข้อมูล</td></tr>';
 $$('#runnersBody tr[data-runner]').forEach(tr=>tr.onclick=()=>{const r=state.runners.find(x=>String(x.RunnerID)===tr.dataset.runner);if(r)fillRunner(r)});
 $$('#runnersBody button[data-upload]').forEach(btn=>btn.onclick=e=>{e.stopPropagation();const [id,type]=btn.dataset.upload.split(':');state.uploadId=id;state.uploadType=type;$('#uploadInput').value='';$('#uploadInput').accept=type==='photo'?'image/png,image/jpeg':'image/png,image/jpeg,application/pdf';$('#uploadInput').click()});
}
function fillRunner(r){
 const form=$('#runnerForm');for(const name of ['RunnerID','Level','FirstName','LastName','CitizenID','BirthYear','Gender'])if(form.elements[name])form.elements[name].value=r[name]??'';
 $('#deleteRunner').hidden=r.Status==='ผ่านการตรวจสอบ';form.scrollIntoView({behavior:'smooth',block:'start'});
}
function resetRunner(){ $('#runnerForm').reset();$('#runnerForm').elements.RunnerID.value='';$('#deleteRunner').hidden=true; }
$('#resetRunner').onclick=resetRunner;
$('#reloadRunners').onclick=()=>guarded(loadRunners);$('#runnerSearch').oninput=renderRunners;
$$('#runnerTabs button').forEach(b=>b.onclick=()=>{state.filter=b.dataset.filter;$$('#runnerTabs button').forEach(n=>n.classList.toggle('active',n===b));renderRunners()});
$('#runnerForm').onsubmit=e=>{e.preventDefault();guarded(async()=>{const f=Object.fromEntries(new FormData(e.target).entries());const r=await api('save_runner',f);resetRunner();msg('บันทึกนักวิ่ง '+r.id+' เรียบร้อย');await loadRunners()})};
$('#deleteRunner').onclick=()=>guarded(async()=>{const id=$('#runnerForm').elements.RunnerID.value;if(!id||!confirm('ยืนยันลบนักวิ่ง '+id+' ?'))return;await api('delete_runner',{id});resetRunner();await loadRunners();msg('ลบนักวิ่งสำเร็จ')});
$('#uploadInput').onchange=()=>guarded(async()=>{
 const file=$('#uploadInput').files[0];if(!file||!state.uploadId)return;
 const f=new FormData();f.append('runnerId',state.uploadId);f.append('type',state.uploadType);f.append('file',file);
 await api('upload',f,true);msg('อัปโหลดไป Google Drive สำเร็จ');state.uploadId=null;await loadRunners();
});
async function loadRegister(){
 const [e,r,regs]=await Promise.all([api('events'),api('runners'),api('registrations')]);
 state.events=e.data||[];state.runners=r.data||[];state.regs=regs.data||[];
 const sel=$('#regEvent');const current=sel.value;
 sel.innerHTML='<option value="">เลือกรายการวิ่ง</option>'+state.events.map(ev=>'<option value="'+esc(ev.EventID)+'">'+esc(ev.EventName)+'</option>').join('');
 if(current)sel.value=current;renderRegs();
}
function eventChanged(){
 const id=Number($('#regEvent').value);const ev=state.events.find(x=>Number(x.EventID)===id);
 $('#regCategory').value=ev?.Category||'';$('#regLevel').value=ev?.Level||'';
 const count=ev?.Category==='วิ่งผลัด'?6:ev?.Category==='วิ่งเดี่ยว'?2:0;
 const elig=state.runners.filter(r=>r.Status==='ผ่านการตรวจสอบ'&&(!ev?.Level||r.Level===ev.Level)&&(!ev?.Gender||r.Gender===ev.Gender));
 $('#regRunnerFields').innerHTML=Array.from({length:count},(_,i)=>`<label>${ev.Category==='วิ่งผลัด'?(i<4?'ตัวจริง '+(i+1):'ตัวสำรอง '+(i-3)):(i===0?'ตัวจริง':'ตัวสำรอง')}<select data-reg-runner><option value="">เลือกนักวิ่ง</option>${elig.map(r=>'<option value="'+esc(r.RunnerID)+'">'+esc(r.FirstName+' '+r.LastName)+' · '+esc(r.Age)+' ปี</option>').join('')}</select></label>`).join('');
}
$('#regEvent').onchange=eventChanged;
function renderRegs(){
 const regs=state.regs||[];
 $('#regsBody').innerHTML=regs.length?regs.map(r=>`<tr data-reg="${esc(r.RegID)}"><td>${esc(r.RegID)}</td><td>${esc(r.EventName)}</td><td>${esc(r.SchoolName)}</td><td>${esc(r.FirstName)}</td><td>${esc(r.Heat||'-')}</td><td>${esc(r.Lane||'-')}</td></tr>`).join(''):'<tr><td colspan="6">ยังไม่มีรายการสมัคร</td></tr>';
 $$('#regsBody [data-reg]').forEach(tr=>tr.onclick=()=>editReg(tr.dataset.reg));
}
function editReg(id){
 const r=state.regs.find(x=>String(x.RegID)===id);if(!r)return;
 $('#regForm').elements.regId.value=id;$('#regEvent').value=r.EventID||state.events.find(e=>e.EventName===r.EventName)?.EventID||'';
 eventChanged();const ids=String(r.RunnerID||'').split(',').map(s=>s.trim());
 $$('[data-reg-runner]').forEach((el,i)=>el.value=ids[i]||'');$('#deleteReg').hidden=false;
 $('#regForm').scrollIntoView({behavior:'smooth'});
}
function resetReg(){$('#regForm').reset();$('#regForm').elements.regId.value='';$('#regSchool').value=state.user?.schoolName||'';$('#regRunnerFields').innerHTML='';$('#deleteReg').hidden=true;}
$('#resetReg').onclick=resetReg;
$('#regForm').onsubmit=e=>{e.preventDefault();guarded(async()=>{
 const eventId=Number($('#regEvent').value),regId=$('#regForm').elements.regId.value;
 const runnerIds=$$('[data-reg-runner]').map(x=>x.value).filter(Boolean);
 if(new Set(runnerIds).size!==runnerIds.length)throw Error('เลือกนักวิ่งซ้ำกัน');
 await api('save_registration',{eventId,regId,runnerIds});msg('บันทึกใบสมัครแล้ว');resetReg();await loadRegister();
})};
$('#deleteReg').onclick=()=>guarded(async()=>{const id=$('#regForm').elements.regId.value;if(!id||!confirm('ลบใบสมัคร '+id+' ?'))return;await api('delete_registration',{id});resetReg();await loadRegister();msg('ลบใบสมัครแล้ว')});
$('#reloadRegs').onclick=()=>guarded(loadRegister);
async function loadSchedule(){
 const r=await api('schedule');state.schedule=r.data||[];
 $('#scheduleBody').innerHTML=state.schedule.length?state.schedule.map((s,i)=>`<tr data-schedule="${i}"><td>${esc(s.EventID)}</td><td>${esc(s.EventName)}</td><td>${esc(s.Category)}</td><td>${esc(s.Level)}</td><td>${esc(s.Gender)}</td><td>${esc(s.Heat||'-')}</td><td>${esc(s.TotalTeams??s.entries.length)}</td></tr><tr id="detail-${i}" class="table-detail" hidden><td colspan="7">${(s.entries||[]).map(x=>esc(x.SchoolName)+' · ฮีท '+esc(x.Heat||'-')+' ลู่ '+esc(x.Lane||'-')+' · '+esc(x.RunnerName)).join('<hr>')||'ไม่มีรายชื่อ'}</td></tr>`).join(''):'<tr><td colspan="7">ยังไม่มีรายการจัดแข่งขัน</td></tr>';
 $$('#scheduleBody tr[data-schedule]').forEach(tr=>tr.onclick=()=>{$('#detail-'+tr.dataset.schedule).hidden=!$('#detail-'+tr.dataset.schedule).hidden});
}
$('#reloadSchedule').onclick=()=>guarded(loadSchedule);
$('#autoHeats').onclick=()=>guarded(async()=>{if(!confirm('ยืนยันสุ่มฮีทและลู่ใหม่ทั้งหมด?'))return;const r=await api('auto_heats',{});msg('จัดฮีทแล้ว '+r.heatsCount+' ฮีท');await loadSchedule()});
$('#printSchedule').onclick=()=>window.print();
async function loadPrint(){
 const [regs,runners]=await Promise.all([api('registrations'),api('runners')]);state.regs=regs.data||[];state.runners=runners.data||[];
 $('#printBody').innerHTML=state.regs.length?state.regs.map(r=>`<tr><td>${esc(r.RegID)}</td><td>${esc(r.EventName)}</td><td>${esc(r.SchoolName)}</td><td>${esc(r.FirstName)}</td><td><button class="secondary" data-print="${esc(r.RegID)}">พิมพ์</button></td></tr>`).join(''):'<tr><td colspan="5">ยังไม่มีข้อมูล</td></tr>';
 $$('#printBody [data-print]').forEach(b=>b.onclick=()=>printRegs([state.regs.find(x=>String(x.RegID)===b.dataset.print)]));
}
function printRegs(regs){
 const w=window.open('','_blank');if(!w){msg('โปรดอนุญาตป๊อปอัป',true);return}
 const runnerMap=new Map(state.runners.map(r=>[String(r.RunnerID),r]));
 const printRows=regs.map(r=>{const rr=String(r.RunnerID||'').split(',').map(x=>runnerMap.get(x.trim())).filter(Boolean);
 return `<article><h2>ใบสมัครเข้าร่วมการแข่งขันกรีฑา</h2><h3>กลุ่มโรงเรียนบางละมุง 1</h3><p><b>RegID:</b> ${esc(r.RegID)}　<b>โรงเรียน:</b> ${esc(r.SchoolName)}</p><p><b>รายการ:</b> ${esc(r.EventName)}　<b>ประเภท:</b> ${esc(r.Category)}　<b>ระดับ:</b> ${esc(r.Level)}</p><p><b>ฮีท:</b> ${esc(r.Heat||'-')}　<b>ลู่:</b> ${esc(r.Lane||'-')}</p><table><thead><tr><th>ลำดับ</th><th>ชื่อ–สกุล</th><th>เพศ</th><th>อายุ</th><th>Verify</th></tr></thead><tbody>${rr.map((x,i)=>`<tr><td>${i+1}</td><td>${esc(x.FirstName+' '+x.LastName)}</td><td>${esc(x.Gender)}</td><td>${esc(x.Age)}</td><td>${x.Status==='ผ่านการตรวจสอบ'?'✓':''}</td></tr>`).join('')}</tbody></table><div class="photos">${rr.map(x=>`<div>${photoUrl(x.Photo,800)?'<img src="'+esc(photoUrl(x.Photo,800))+'">':''}<small>${esc(x.FirstName+' '+x.LastName)}</small></div>`).join('')}</div><p>ลงชื่อ .................................................... ครูผู้ฝึกซ้อม</p><p>ผลการแข่งขัน: ☐ ตกรอบ　☐ เข้ารอบชิง　☐ อันดับ 1　☐ อันดับ 2　☐ อันดับ 3</p></article>`}).join('');
 w.document.write('<!doctype html><html lang="th"><meta charset="utf-8"><title>ใบสมัครกรีฑา</title><style>@page{size:A4;margin:12mm}body{font:16px Sarabun,sans-serif;color:#111}article{break-after:page}h2,h3{text-align:center}h2{margin:0}h3{margin:4px 0 20px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #aaa;padding:8px}.photos{display:flex;gap:10px;margin:15px 0}.photos div{max-width:100px;flex:1;text-align:center}.photos img{width:100%;aspect-ratio:1;object-fit:cover}.photos small{display:block}</style>'+printRows+'</html>');w.document.close();w.focus();setTimeout(()=>w.print(),350);
}
$('#printAll').onclick=()=>printRegs(state.regs);
async function loadAdmin(){
 const r=await api('runners');state.runnersAll=r.data||[];renderAdmin();
}
function renderAdmin(){
 const q=$('#adminSearch').value.trim().toLowerCase();
 const d=state.runnersAll.filter(r=>(r.FirstName+' '+r.LastName+' '+r.SchoolName).toLowerCase().includes(q));
 $('#adminBody').innerHTML=d.map(r=>`<tr><td>${esc(r.RunnerID)}</td><td>${esc(r.FirstName+' '+r.LastName)}</td><td>${esc(r.SchoolName)}</td><td>${link(r.Photo)?'<a target="_blank" rel="noopener" href="'+link(r.Photo)+'">รูป</a>':''} ${link(r.IDProofURL)?'<a target="_blank" rel="noopener" href="'+link(r.IDProofURL)+'">หลักฐาน</a>':''}</td><td><select data-status="${esc(r.RunnerID)}">${['รอตรวจสอบ','รอตรวจภาพอีกครั้ง','ผ่านการตรวจสอบ','ไม่ผ่านการตรวจสอบ'].map(s=>'<option '+(s===r.Status?'selected':'')+'>'+esc(s)+'</option>').join('')}</select><input data-reason="${esc(r.RunnerID)}" placeholder="เหตุผลไม่ผ่าน" value="${esc(r.FailReason||'')}"></td><td><button data-save-status="${esc(r.RunnerID)}">บันทึก</button></td></tr>`).join('');
 $$('#adminBody [data-save-status]').forEach(b=>b.onclick=()=>guarded(async()=>{const id=b.dataset.saveStatus;const status=$('[data-status="'+id+'"]').value,reason=$('[data-reason="'+id+'"]').value;await api('update_status',{id,status,reason});msg('อัปเดตสถานะแล้ว');await loadAdmin()}));
}
$('#adminSearch').oninput=renderAdmin;
async function loadLogs(){const r=await api('logs');$('#logsBody').innerHTML=r.data.map(x=>`<tr><td>${esc(x.event_time)}</td><td>${esc(x.action_code)}</td><td>${esc(x.user_name)}</td><td>${esc(x.user_school)}</td><td>${esc(x.detail)}</td></tr>`).join('')}
async function loadCertificates(){const r=await api('certificates');$('#certBody').innerHTML=r.data.map(x=>`<tr><td>${esc(x.event_name)}</td><td>${esc(x.medal)}</td><td>${esc(x.school_name)}</td><td>${esc(x.athlete_name)}</td><td>${esc(x.certificate_number)}</td></tr>`).join('')}
async function loadResults(){const r=await api('results');$('#resultsBody').textContent='นำเข้าข้อมูลผลการแข่งขัน '+r.data.length+' แถว (ข้อมูลต้นฉบับถูกเก็บไว้ใน MariaDB สำหรับพัฒนาหน้ารายงานฉบับสมบูรณ์)'}
guarded(loadUser);
