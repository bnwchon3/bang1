/* Manager/Admin: checkbox-based athlete check-in for the active competition. */
(function(){
  'use strict';
  const el=id=>document.getElementById(id);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let snapshot=null,busy=false,loading=false,filter='',lastActionAt=0;
  function isManager(){
    const user=typeof currentUser==='undefined'?null:currentUser;
    return !!user&&['Manager','Admin','TopAdmin'].includes(user.role);
  }
  function activePage(){return !!el('page-checkin')?.classList.contains('active');}
  function request(action,data){
    if(!window.bang1Bridge?.adminRequest)return Promise.reject(Error('ยังไม่ได้เชื่อมต่อระบบ'));
    return window.bang1Bridge.adminRequest(action,data);
  }
  function message(text,kind='info'){
    const node=el('bang1ScanStatus');if(!node)return;
    node.textContent=text;
    node.className='bang1-scan-status is-'+kind;
  }
  function fixtureName(f){
    return [f.eventName,f.category,f.level,f.gender,f.heat?'ฮีท '+f.heat:''].filter(Boolean).join(' · ');
  }
  function athleteCard(athlete,regId){
    const checked=Boolean(athlete.checkedAt);
    const photo=athlete.photo
      ?'<img src="'+esc(athlete.photo)+'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=&quot;none&quot;">'
      :'<span class="bang1-avatar-fallback" aria-hidden="true">🏃</span>';
    return '<label class="bang1-athlete-row'+(checked?' is-checked':'')+'">'+
      '<input type="checkbox" data-reg="'+Number(regId)+'" data-athlete="'+esc(athlete.athleteKey)+'"'+
        (checked?' checked':'')+' aria-label="รายงานตัว '+esc(athlete.name)+'">'+
      '<span class="bang1-athlete-photo">'+photo+'</span>'+
      '<span class="bang1-athlete-info"><strong>'+esc(athlete.name)+'</strong>'+
       '<small>'+(checked?'✓ รายงานตัวแล้ว '+esc(athlete.checkedAt):'ยังไม่รายงานตัว')+'</small></span>'+
      '<span class="bang1-athlete-status'+(checked?' confirmed':'')+'">'+(checked?'✓':'○')+'</span>'+
      '</label>';
  }
  function renderRoster(){
    const area=el('bang1CheckinRoster');
    if(!area)return;
    const teams=Array.isArray(snapshot?.teams)?snapshot.teams:[];
    const active=snapshot?.active;
    if(!active){
      area.innerHTML='<div class="bang1-roster-empty">ยังไม่ได้เปิดรายการ Active · โปรดเลือกการแข่งขันด้านบน</div>';
      el('bang1CheckinCount').textContent='0 / 0 รายงานตัว';return;
    }
    el('bang1CheckinCount').textContent=Number(active.checked)+' / '+Number(active.total)+' รายงานตัว';
    const term=filter.toLocaleLowerCase('th').trim();
    const visible=teams.map(team=>{
      const athletes=(team.athletes||[]).filter(a=>!term||
        [a.name,team.school,team.regId].some(v=>String(v||'').toLocaleLowerCase('th').includes(term)));
      return {...team,athletes};
    }).filter(team=>team.athletes.length>0);
    if(!visible.length){
      area.innerHTML='<div class="bang1-roster-empty">'+
        (term?'ไม่พบชื่อนักกรีฑาตามคำค้นหา':'รายการนี้ยังไม่มีนักกรีฑาที่สมัครไว้')+'</div>';return;
    }
    area.innerHTML=visible.map(team=>
      '<section class="bang1-roster-school">'+
      '<header><div><strong>'+esc(team.school)+'</strong><small>ใบสมัคร #'+Number(team.regId)+
      (team.lane?' · ลู่ '+Number(team.lane):'')+'</small></div>'+
      '<span>'+Number(team.checkedAthletes)+' / '+Number(team.totalAthletes)+' คน</span></header>'+
      '<div class="bang1-athlete-list">'+team.athletes.map(a=>athleteCard(a,team.regId)).join('')+'</div>'+
      '</section>').join('');
  }
  function render(data){
    snapshot=data;
    const select=el('bang1ActiveFixture');
    const selected=select.value;
    const fixtures=Array.isArray(data.fixtures)?data.fixtures:[];
    select.replaceChildren(new Option('เลือกรายการแข่งขัน/ฮีท',''));
    fixtures.forEach((f,i)=>select.appendChild(new Option(
      (i+1)+'. '+fixtureName(f)+' · '+Number(f.checked)+'/'+Number(f.total)+' คน',f.key)));
    if(selected&&fixtures.some(f=>f.key===selected))select.value=selected;
    else if(data.active)select.value=data.active.key;
    const active=data.active;
    el('bang1LiveActiveName').textContent=active?fixtureName(active):'ยังไม่ได้เปิดรายการ Active';
    el('bang1LiveActiveCount').textContent=active?
      'รายงานตัว '+active.checked+'/'+active.total+' คน · '+active.teams+' โรงเรียนที่ลงทะเบียน':
      'เลือกการแข่งขันแล้วกดแสดงรายการบนจอ Live';
    el('bang1ActiveEnd').disabled=!active;
    renderRoster();
  }
  async function refresh(showNotice=false){
    if(!isManager()||!activePage()||busy||loading)return;
    loading=true;
    try{
      const result=await request('live_manager_data');
      render(result);
      if(showNotice)message('อัปเดตข้อมูลล่าสุดเรียบร้อย','success');
    }catch(err){message(err.message||'ไม่สามารถโหลดรายชื่อนักกรีฑาได้','error')}
    finally{loading=false}
  }
  async function update(action,payload){
    if(busy)return false;
    busy=true;
    try{
      const result=await request(action,payload);
      render(await request('live_manager_data'));
      message(result.message||'บันทึกสำเร็จ','success');
      lastActionAt=Date.now();
      return true;
    }catch(err){message(err.message||'บันทึกไม่สำเร็จ','error');return false}
    finally{busy=false}
  }
  function setup(){
    const page=el('page-checkin');if(!page)return;
    el('bang1SetActive').addEventListener('click',()=>{
      const key=el('bang1ActiveFixture').value;
      if(!key){message('กรุณาเลือกรายการแข่งขันก่อน','error');return}
      update('live_select',{fixtureKey:key});
    });
    el('bang1ActiveEnd').addEventListener('click',()=>{
      if(confirm('ยืนยันปิดรายการ Active บนจอ Live?'))update('live_select',{fixtureKey:''});
    });
    el('bang1CheckinRefresh').addEventListener('click',()=>refresh(true));
    el('bang1AthleteSearch').addEventListener('input',e=>{
      filter=e.target.value;renderRoster();
    });
    el('bang1CheckinRoster').addEventListener('change',async e=>{
      const box=e.target.closest('input[type="checkbox"][data-athlete]');
      if(!box||busy)return;
      const regId=Number(box.dataset.reg);
      const athleteKey=box.dataset.athlete;
      const checked=box.checked;
      box.disabled=true;
      message('กำลังบันทึกการรายงานตัว...','info');
      const succeeded=await update('live_runner_set',{regId,athleteKey,checked});
      if(!succeeded){
        box.checked=!checked;box.disabled=false;
      }
    });
    const menu=document.querySelector('.menu button[data-page="checkin"]');
    if(menu)menu.addEventListener('click',()=>setTimeout(()=>refresh(),0));
    const observer=new MutationObserver(()=>{if(activePage())refresh()});
    observer.observe(page,{attributes:true,attributeFilter:['class']});
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refresh()});
    setInterval(()=>{if(activePage()&&Date.now()-lastActionAt>1500)refresh()},4000);
    refresh();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup);
  else setup();
})();
