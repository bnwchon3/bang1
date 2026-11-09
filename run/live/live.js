/* Public Athletics LIVE • full-screen, ten lanes, no scroll, current + next. */
(function(){
  'use strict';
  const el=id=>document.getElementById(id);
  const esc=value=>String(value??'').replace(/[&<>"']/g,
    char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  let requestBusy=false,loaded=false,lastSignature='',lastResultSignature='';
  const fallbackSchools=[
    'วัดสุทธารีย์บุญญาราม','บ้านตะเคียนเตี้ย','บ้านสันติคาม','บ้านบางละมุง',
    'วัดหนองเกตุใหญ่','อนุบาลบางละมุง','วัดเวฬุวนาราม','บ้านโรงหีบ',
    'บ้านนาวัง','บ้านทุ่งกราด'
  ];
  function timeStamp(){
    return new Intl.DateTimeFormat('th-TH',{
      timeZone:'Asia/Bangkok',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false
    }).format(new Date());
  }
  function athleteCard(person){
    const image=person.photo
      ?'<img src="'+esc(person.photo)+'" loading="eager" decoding="async" alt="" referrerpolicy="no-referrer">'
      :'<span class="portrait-placeholder" aria-hidden="true">🏃</span>';
    return '<article class="athlete" title="'+esc(person.name)+'">'+
      '<div class="athlete-portrait">'+image+'</div>'+
      '<div class="athlete-name">'+esc(person.name)+'</div>'+
      '</article>';
  }
  function schoolCell(school){
    const athletes=Array.isArray(school.athletes)?school.athletes:[];
    const entered=Boolean(school.registered);
    const count=athletes.length;
    const layout=count<=1?'count-1':count===2?'count-2':
      count<=4?'count-4':'count-6';
    return '<section class="school-column'+(entered?' is-entered':'')+'">'+
      '<header class="school-name">'+esc(String(school.name||'').replace(/^โรงเรียน/u,''))+'</header>'+
      '<div class="school-athletes '+layout+'">'+
      (count ? athletes.map(athleteCard).join('') :
        '<span class="no-athletes">'+(entered?'รอรายงานตัว':'')+'</span>')+
      '</div></section>';
  }
  function renderRecentResults(results){
    const valid=Array.isArray(results)?results.slice(0,8).filter(result=>
      result && typeof result.eventName==='string' &&
      Array.isArray(result.places) && result.places.length>0):[];
    const signature=JSON.stringify(valid);
    if(signature===lastResultSignature)return;
    lastResultSignature=signature;
    const track=el('latestResultTicker');
    if(!track)return;
    const medals={1:'🥇',2:'🥈',3:'🥉'};
    const messages=valid.length?valid.map(result=>{
      const heat=Number(result.heat)>0?' · ฮีท '+Number(result.heat):'';
      const title=esc(result.eventName);
      const round=esc(result.round||'');
      const places=result.places.slice(0,3).map(entry=>{
        const rank=Number(entry.place);
        const school=esc(entry.school||'');
        if(!school)return '';
        return '<span class="ticker-place'+(rank===1?' is-first':'')+'">'+
          (medals[rank]||'อันดับ '+rank)+' '+school+'</span>';
      }).filter(Boolean).join('');
      return '<span class="ticker-result"><span class="ticker-event">'+title+'</span>'+
        '<span class="ticker-round">'+round+heat+'</span>'+places+
        '<span class="ticker-separator" aria-hidden="true">✦</span></span>';
    }).join(''):'<span class="ticker-result"><span class="ticker-placeholder">'+
      'ยังไม่มีผลการแข่งขันที่บันทึกอย่างเป็นทางการ · รอเจ้าหน้าที่ประกาศผล'+
      '</span><span class="ticker-separator" aria-hidden="true">✦</span></span>';
    const sequence='<div class="ticker-sequence">'+messages+'</div>';
    track.innerHTML=sequence+
      '<div class="ticker-sequence" aria-hidden="true">'+messages+'</div>';
    // Longer result feeds move farther; adjust duration to retain a slow,
    // readable horizontal travel without resizing the parent scoreboard.
    const approximateChars=valid.length?
      valid.reduce((sum,r)=>sum+String(r.eventName).length+
        String(r.round||'').length+
        r.places.slice(0,3).reduce((n,p)=>n+String(p.school||'').length+12,0)+25,0)
      :75;
    const seconds=Math.max(65,Math.min(210,42+approximateChars*.20));
    track.style.setProperty('--ticker-duration',seconds+'s');
  }
  function renderNext(next){
    const title=el('nextFixture'),meta=el('nextMeta'),count=el('nextCount'),fill=el('nextFill');
    if(!next){
      title.textContent='ยังไม่มีรายการถัดไป';
      meta.textContent='กรุณารอ Manager เลือกรายการแข่งขัน';
      count.textContent='—';
      fill.style.width='0%';
      return;
    }
    title.textContent='รายการที่ '+(Number(next.eventId)||'—')+'  '+next.eventName;
    meta.textContent=[next.category,next.level,next.gender,next.heat?'ฮีทที่ '+next.heat:''].filter(Boolean).join(' · ');
    count.textContent=Number(next.checked)+' / '+Number(next.total)+' คน';
    const percent=Number(next.total)>0
      ?Math.min(100,Math.max(0,Math.round(Number(next.checked)*100/Number(next.total))))
      :0;
    fill.style.width=percent+'%';
  }
  function render(payload){
    const active=payload.active;
    const schools=Array.isArray(payload.schools)&&payload.schools.length===10
      ?payload.schools
      :fallbackSchools.map(name=>({name:'โรงเรียน'+name,registered:false,athletes:[]}));
    const next=active&&Array.isArray(payload.next)?payload.next[0]||null:null;
    const signature=JSON.stringify([active,schools,next]);
    if(signature!==lastSignature){
      lastSignature=signature;
      el('eventNumber').textContent=active?
        String(Number(active.eventId)||Number(active.order)||'—'):'—';
      el('fixtureTitle').textContent=active?active.eventName:'รอ Manager เลือกรายการแข่งขัน';
      el('roundLabel').textContent=active?
        (Number(active.teams)<=8?'รอบชิง':'รอบคัดเลือก'):'ยังไม่เริ่ม';
      const grid=el('schoolGrid');
      grid.innerHTML=schools.map(schoolCell).join('');
      // On unavailable or access-restricted source photos, keep each
      // athlete card visible rather than leaving a broken-image icon.
      for(const img of grid.querySelectorAll('.athlete-portrait img')){
        img.addEventListener('error',function(){
          const fallback=document.createElement('span');
          fallback.className='portrait-placeholder';
          fallback.setAttribute('aria-hidden','true');
          fallback.textContent='🏃';
          this.replaceWith(fallback);
        },{once:true});
      }
      renderNext(next);
    }
    renderRecentResults(payload.latestResults);
    loaded=true;
    el('connection').className='connected';
    el('connection').textContent='● LIVE • เชื่อมต่อแล้ว';
    el('lastRefresh').textContent='อัปเดต '+timeStamp();
  }
  async function poll(){
    if(requestBusy||document.visibilityState==='hidden')return;
    requestBusy=true;
    try{
      const response=await fetch('../api.php?action=live_state',{
        cache:'no-store',credentials:'same-origin'
      });
      if(!response.ok)throw Error('HTTP '+response.status);
      const payload=await response.json();
      if(payload.success!==true)throw Error(payload.message||'การเชื่อมต่อขัดข้อง');
      render(payload);
    }catch(error){
      el('connection').className='';
      el('connection').textContent='● ออฟไลน์';
      el('lastRefresh').textContent=loaded
        ?'ข้อมูลล่าสุดที่โหลดสำเร็จ':'ไม่สามารถโหลดข้อมูลได้';
    }finally{
      requestBusy=false;
    }
  }
  el('fullScreen').addEventListener('click',async()=>{
    try{
      if(document.fullscreenElement)await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    }catch(error){}
  });
  document.addEventListener('visibilitychange',()=>{
    if(document.visibilityState==='visible')void poll();
  });
  void poll();
  setInterval(poll,3000);
})();
