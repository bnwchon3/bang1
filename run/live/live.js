/* Simple public race call: ten fixed school columns, only checked athletes. */
(function(){
  'use strict';
  const el=id=>document.getElementById(id);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let requestBusy=false,lastSignature='',loaded=false;
  const initialSchools=[
    'วัดสุทธารีย์บุญญาราม','บ้านตะเคียนเตี้ย','บ้านสันติคาม',
    'บ้านบางละมุง','วัดหนองเกตุใหญ่','อนุบาลบางละมุง',
    'วัดเวฬุวนาราม','บ้านโรงหีบ','บ้านนาวัง','บ้านทุ่งกราด'
  ];
  function timeStamp(){
    return new Intl.DateTimeFormat('th-TH',{
      timeZone:'Asia/Bangkok',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false
    }).format(new Date());
  }
  function photo(athlete){
    if(!athlete.photo)return '<div class="portrait-placeholder" aria-hidden="true">🏃</div>';
    return '<img loading="lazy" alt="" src="'+esc(athlete.photo)+'" referrerpolicy="no-referrer">';
  }
  function schoolCell(school){
    const athletes=Array.isArray(school.athletes)?school.athletes:[];
    return '<section class="school-column">'+
      '<header class="school-name">'+esc(String(school.name||'').replace(/^โรงเรียน/u,''))+'</header>'+
      '<div class="school-athletes">'+(athletes.length?
      athletes.map(a=>'<article class="athlete">'+photo(a)+
        '<div class="athlete-name">'+esc(a.name)+'</div>'+
        '<div class="athlete-id">#'+Number(a.regId)+'</div></article>').join(''):
      '<div class="no-athletes"></div>')+'</div></section>';
  }
  function draw(payload){
    const f=payload.active;
    const schools=Array.isArray(payload.schools)&&payload.schools.length?
      payload.schools:initialSchools.map(s=>({name:'โรงเรียน'+s,athletes:[]}));
    const signature=JSON.stringify([f,schools]);
    if(signature!==lastSignature){
      lastSignature=signature;
      el('eventNumber').textContent=f?
        String(Number(f.order)||((payload.fixtures||[]).findIndex(x=>x.key===f.key)+1)||'—'):'—';
      el('fixtureTitle').textContent=f?f.eventName:'รอ Manager เลือกรายการแข่งขัน';
      // With 1-8 schools, the event goes straight to the final under the
      // competition's established scheduling rules.
      el('roundLabel').textContent=f?(Number(f.teams)<=8?'รอบชิง':'รอบคัดเลือก'):'ยังไม่เริ่ม';
      el('fixtureMeta').textContent=f?
        [f.category,f.level,f.gender,f.heat?'ฮีทที่ '+f.heat:''].filter(Boolean).join(' · '):
        'กลุ่มโรงเรียนบางละมุง 1 · เช็กชื่อรายงานตัว';
      el('checkedCount').textContent=f?
        'รายงานตัวแล้ว '+Number(f.checked)+' / '+Number(f.total)+' คน':'รอเปิดรายการ';
      const grid=el('schoolGrid');
      grid.innerHTML=schools.slice(0,10).map(schoolCell).join('');
      for(const img of grid.querySelectorAll('img')){
        img.addEventListener('error',function(){
          const fallback=document.createElement('div');
          fallback.className='portrait-placeholder';
          fallback.textContent='🏃';
          this.replaceWith(fallback);
        },{once:true});
      }
    }
    loaded=true;
    el('connection').className='connected';
    el('connection').textContent='● LIVE';
    el('lastRefresh').textContent='อัปเดต '+timeStamp();
  }
  async function refresh(){
    if(requestBusy||document.visibilityState==='hidden')return;
    requestBusy=true;
    try{
      const response=await fetch('../api.php?action=live_state',{
        cache:'no-store',credentials:'same-origin'
      });
      if(!response.ok)throw Error('HTTP '+response.status);
      const result=await response.json();
      if(result.success!==true)throw Error(result.message||'ไม่สามารถโหลดข้อมูล');
      draw(result);
    }catch(error){
      el('connection').className='';
      el('connection').textContent='● การเชื่อมต่อขัดข้อง';
      el('lastRefresh').textContent=loaded?'แสดงข้อมูลที่อัปเดตล่าสุด':'โหลดข้อมูลไม่สำเร็จ';
    }finally{requestBusy=false;}
  }
  el('fullScreen').addEventListener('click',async function(){
    try{
      if(document.fullscreenElement)await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    }catch(error){}
  });
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refresh()});
  refresh();
  setInterval(refresh,3000);
})();
