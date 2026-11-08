/* Public read-only, polling-based live race check-in screen. */
(function(){
  'use strict';
  const $=id=>document.getElementById(id);
  const escapeHtml=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let oldActive='',oldChecked=new Set(),initialized=false,requesting=false,toastTimer=null;
  function timeOnly(text){
    if(!text)return '—';
    const match=String(text).match(/\d{2}:\d{2}:\d{2}/);
    return match?match[0]:String(text);
  }
  function clock(){
    $('liveClock').textContent=new Intl.DateTimeFormat('th-TH',{timeZone:'Asia/Bangkok',
      hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date());
  }
  function connection(online,message){
    $('connect').textContent=(online?'● ออนไลน์':'● '+(message||'เชื่อมต่อไม่ได้'));
    $('connect').className='connection '+(online?'online':'offline');
  }
  function update(payload){
    const f=payload.active;
    const key=f?.key||'';
    const teams=Array.isArray(payload.teams)?payload.teams:[];
    const checkedNow=new Set(teams.filter(t=>t.checkedAt).map(t=>Number(t.regId)));
    let arrivals=[];
    if(initialized&&key===oldActive) {
      arrivals=teams.filter(t=>t.checkedAt&&!oldChecked.has(Number(t.regId)));
      if(arrivals.length){
        const last=arrivals[arrivals.length-1];
        const msg='✓ รายงานตัวแล้ว: '+last.school+' · ใบสมัคร #'+last.regId;
        $('scanToast').textContent=msg;
        $('scanToast').hidden=false;
        clearTimeout(toastTimer);
        toastTimer=setTimeout(()=>{$('scanToast').hidden=true},6500);
      }
    }else{$('scanToast').hidden=true;clearTimeout(toastTimer);}
    oldActive=key;oldChecked=checkedNow;initialized=true;

    $('fixtureTitle').textContent=f?.eventName||'รอ Manager เลือกรายการแข่งขัน';
    $('fixtureMeta').textContent=f?[f.category,f.level,f.gender].filter(Boolean).join(' • '):
      'เมื่อ Manager เปิดรายการ หน้าจอนี้จะแสดงสถานะโดยอัตโนมัติ';
    $('fixtureHeat').textContent=f?(f.heat?'ฮีทที่ '+f.heat:'รายการแข่งขัน'):'รอเปิดรายการ';
    const total=Number(f?.total||0),confirmed=Number(f?.checked||0),pending=Math.max(0,total-confirmed);
    $('total').textContent=f?total.toLocaleString('th-TH'):'—';
    $('checked').textContent=f?confirmed.toLocaleString('th-TH'):'—';
    $('pending').textContent=f?pending.toLocaleString('th-TH'):'—';
    const percent=total?Math.min(100,Math.round(100*confirmed/total)):0;
    $('progressText').textContent=percent+'%';
    $('progressRing').style.setProperty('--fill',percent+'%');
    $('rosterCount').textContent=f?(confirmed+' / '+total+' รายงานตัวแล้ว'):'ยังไม่เลือกรายการ';
    $('roster').innerHTML=f?(
      teams.length?teams.map(t=>
        '<div class="school-card'+(t.checkedAt?' done':'')+(arrivals.some(x=>Number(x.regId)===Number(t.regId))?' flash':'')+'" data-reg="'+Number(t.regId)+'">'+
         '<div class="school-main"><strong>'+escapeHtml(t.school)+'</strong>'+
          '<small>ใบสมัคร #'+Number(t.regId)+(t.lane?' · ลู่วิ่ง '+Number(t.lane):'')+'</small></div>'+
         '<span class="school-state">'+(t.checkedAt?'✓ รายงานตัวแล้ว':'◷ รอรายงานตัว')+'</span>'+
        '</div>').join(''):'<div class="empty">ยังไม่มีใบสมัครสำหรับรายการนี้</div>'
    ):'<div class="empty">รอ Manager เปิดรายการ Active เพื่อเริ่มรายงานตัว</div>';
    const recent=Array.isArray(payload.recent)?payload.recent:[];
    $('recent').innerHTML=recent.length?recent.map(t=>
       '<div class="recent-card"><div><strong>✓ '+escapeHtml(t.school)+'</strong>'+
       '<small>ใบสมัคร #'+Number(t.regId)+'</small></div>'+
       '<span class="recent-time">'+escapeHtml(timeOnly(t.checkedAt))+'</span></div>'
    ).join(''):'<div class="empty">ยังไม่มีการรายงานตัวในรายการนี้</div>';
    const upcoming=Array.isArray(payload.next)?payload.next:[];
    $('upcoming').innerHTML=upcoming.length?upcoming.map((x,i)=>
      '<div class="upcoming-item"><div><strong>'+escapeHtml(x.eventName)+'</strong>'+
      '<small>'+escapeHtml([x.category,x.level,x.gender,x.heat?'ฮีท '+x.heat:''].filter(Boolean).join(' · '))+'</small></div>'+
      '<span class="queue-no">'+(Number(x.checked)||0)+'/'+(Number(x.total)||0)+'</span></div>'
    ).join(''):'<div class="empty">ไม่มีรายการถัดไป</div>';
    $('lastRefresh').textContent='อัปเดตล่าสุด '+new Intl.DateTimeFormat('th-TH',{timeZone:'Asia/Bangkok',
      hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date());
  }
  async function poll(){
    if(requesting||document.visibilityState==='hidden')return;
    requesting=true;
    try{
      const res=await fetch('../api.php?action=live_state',{cache:'no-store',credentials:'same-origin'});
      if(!res.ok)throw Error('HTTP '+res.status);
      const json=await res.json();
      if(!json.success)throw Error(json.message||'ข้อมูลไม่พร้อม');
      update(json);
      connection(true);
    }catch(err){
      connection(false,'การเชื่อมต่อขัดข้อง');
      $('lastRefresh').textContent='อัปเดตไม่สำเร็จ · ข้อมูลที่แสดงเป็นข้อมูลครั้งล่าสุด';
      if(!initialized)$('roster').innerHTML='<div class="empty">ไม่สามารถโหลดข้อมูล Live ได้ กรุณาตรวจสอบการเชื่อมต่อ</div>';
    }finally{requesting=false;}
  }
  $('fullScreen').addEventListener('click',async function(){
    try {
      if(document.fullscreenElement)await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    }catch(e){}
  });
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible')poll()});
  window.setInterval(clock,1000);
  window.setInterval(poll,3000);
  clock();poll();
})();
