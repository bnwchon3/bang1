/* Athletics public display: CHECK-IN / SPORT, synchronized 15s Sport carousel. */
(function(){
  'use strict';
  const el=id=>document.getElementById(id);
  const esc=value=>String(value??'').replace(/[&<>"']/g,
    char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const fallbackSchools=[
    'วัดสุทธารีย์บุญญาราม','บ้านตะเคียนเตี้ย','บ้านสันติคาม',
    'บ้านบางละมุง','วัดหนองเกตุใหญ่','อนุบาลบางละมุง',
    'วัดเวฬุวนาราม','บ้านโรงหีบ','บ้านนาวัง','บ้านทุ่งกราด'
  ];
  let requestBusy=false,loaded=false,lastSignature='',lastResultSignature='';
  let lastMode=null,lastPayload=null,clockOffsetMs=0,sportSlideIndex=-1;
  let sportSlideToken=0,lastSportBoardSignature='',sportCache=new Map(),sportPending=new Map(),sportRetryAt=0;

  function stamp(){
    return new Intl.DateTimeFormat('th-TH',{
      timeZone:'Asia/Bangkok',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false
    }).format(new Date());
  }
  function athleteCard(person,sport=false){
    // Never synthesize a portrait of a real registered child.
    const image=person.photo
      ?'<img src="'+esc(person.photo)+'" loading="eager" decoding="async" alt="" referrerpolicy="no-referrer">'
      :'<span class="portrait-placeholder" aria-label="ไม่มีรูปนักกรีฑา">'+
        (sport?'ยังไม่มีรูป':'🏃')+'</span>';
    return '<article class="athlete" title="'+esc(person.name)+'">'+
      '<div class="athlete-portrait">'+image+'</div>'+
      '<div class="athlete-name">'+esc(person.name)+'</div>'+
      '</article>';
  }
  function schoolCell(school,sport=false){
    const athletes=Array.isArray(school.athletes)?school.athletes:[];
    const entered=Boolean(school.registered);
    const count=athletes.length;
    const layout=count<=1?'count-1':count===2?'count-2':
      count<=4?'count-4':count<=6?'count-6':'count-many';
    const rows=Math.ceil(Math.max(1,count)/2);
    const heats=[...new Set((Array.isArray(school.heats)?school.heats:[])
      .map(Number).filter(h=>Number.isInteger(h)&&h>0&&h<=99))].sort((a,b)=>a-b);
    const badges=entered&&heats.length
      ?'<span class="school-heat-badges" aria-label="ฮีทของโรงเรียน">'+
        heats.map(h=>'<span class="school-heat-badge '+(h===1?'heat-one':h===2?'heat-two':'heat-other')+
          '" aria-label="ฮีทที่ '+h+'" title="ฮีทที่ '+h+'">'+h+'</span>').join('')+
        '</span>':'';
    return '<section class="school-column'+(entered?' is-entered':'')+'">'+
      '<header class="school-name"><span class="school-name-text">'+
      esc(String(school.name||'').replace(/^โรงเรียน/u,''))+'</span>'+badges+'</header>'+
      '<div class="school-athletes '+layout+'" style="--athlete-rows:'+rows+'">'+
      (count?athletes.map(a=>athleteCard(a,sport)).join(''):
        '<span class="no-athletes">'+(entered?(sport?'รอภาพนักกรีฑา':'รอรายงานตัว'):'')+'</span>')+
      '</div></section>';
  }
  function paintSchools(schools,sport=false){
    const valid=Array.isArray(schools)&&schools.length===10?schools:
      fallbackSchools.map(name=>({name:'โรงเรียน'+name,registered:false,heats:[],athletes:[]}));
    const grid=el('schoolGrid');
    grid.innerHTML=valid.map(s=>schoolCell(s,sport)).join('');
    for(const img of grid.querySelectorAll('.athlete-portrait img')){
      img.addEventListener('error',function(){
        const fallback=document.createElement('span');
        fallback.className='portrait-placeholder';
        fallback.setAttribute('aria-hidden','true');
        fallback.textContent=sport?'ยังไม่มีรูป':'🏃';
        this.replaceWith(fallback);
      },{once:true});
    }
  }
  function renderRecentResults(results){
    const valid=Array.isArray(results)?results.slice(0,8).filter(result=>
      result&&typeof result.eventName==='string'&&
      Array.isArray(result.places)&&result.places.length>0):[];
    const signature=JSON.stringify(valid);
    if(signature===lastResultSignature)return;
    lastResultSignature=signature;
    const track=el('latestResultTicker');
    if(!track)return;
    const medals={1:'🥇',2:'🥈',3:'🥉'};
    const messages=valid.length?valid.map(result=>{
      const heat=Number(result.heat)>0?' · ฮีท '+Number(result.heat):'';
      const places=result.places.slice(0,3).map(place=>{
        const rank=Number(place.place),school=esc(place.school||'');
        if(!school)return '';
        return '<span class="ticker-place'+(rank===1?' is-first':'')+'">'+
          (medals[rank]||'อันดับ '+rank)+' '+school+'</span>';
      }).filter(Boolean).join('');
      return '<span class="ticker-result"><span class="ticker-event">'+esc(result.eventName)+'</span>'+
        '<span class="ticker-round">'+esc(result.round||'')+heat+'</span>'+places+
        '<span class="ticker-separator" aria-hidden="true">✦</span></span>';
    }).join(''):'<span class="ticker-result"><span class="ticker-placeholder">'+
      'ยังไม่มีผลการแข่งขันที่บันทึกอย่างเป็นทางการ · รอเจ้าหน้าที่ประกาศผล'+
      '</span><span class="ticker-separator" aria-hidden="true">✦</span></span>';
    const sequence='<div class="ticker-sequence">'+messages+'</div>';
    track.innerHTML=sequence+'<div class="ticker-sequence" aria-hidden="true">'+messages+'</div>';
    const charCount=valid.length?
      valid.reduce((sum,r)=>sum+String(r.eventName).length+String(r.round||'').length+
        r.places.slice(0,3).reduce((n,p)=>n+String(p.school||'').length+12,0)+25,0):75;
    track.style.setProperty('--ticker-duration',
      Math.max(65,Math.min(210,42+charCount*.2))+'s');
  }
  function setNextLabels(sport){
    el('nextIcon').textContent=sport?'🏟️':'⏭';
    el('nextEyebrow').firstChild.textContent=sport?'SPORT SHOWCASE ':'NEXT UP ';
    el('nextAccent').textContent=sport?'• หมุนเวียนรายการอัตโนมัติ':'• รับรายงานตัวล่วงหน้า';
    el('nextProgressTitle').textContent=sport?'กำลังแสดงรายการ':'รายงานตัวแล้ว';
    el('eventHeading').textContent=sport?'SPORT • รายการแข่งขันที่ ':'รายการแข่งขันที่ ';
  }
  function nextEvent(f){
    const title=el('nextFixture'),meta=el('nextMeta'),count=el('nextCount'),fill=el('nextFill');
    if(!f){
      title.textContent='ยังไม่มีรายการถัดไป';
      meta.textContent='กรุณารอ Manager เลือกรายการแข่งขัน';
      count.textContent='—';fill.style.width='0%';return;
    }
    title.textContent='รายการที่ '+(f.eventNumberLabel||Number(f.eventId)||'—')+'  '+f.eventName;
    const heat=Array.isArray(f.heats)&&f.heats.length>1?'ฮีท '+f.heats.join('–'):
      (f.heat?'ฮีทที่ '+f.heat:'');
    meta.textContent=[f.category,f.level,f.gender,heat].filter(Boolean).join(' · ');
    count.textContent=Number(f.checked)+' / '+Number(f.total)+' คน';
    fill.style.width=(Number(f.total)>0?
      Math.min(100,Math.max(0,Math.round(Number(f.checked)*100/Number(f.total)))):0)+'%';
  }
  function renderCheckin(payload){
    const active=payload.active;
    const next=active&&Array.isArray(payload.next)?payload.next[0]||null:null;
    const signature=JSON.stringify([active,payload.schools,next]);
    if(signature===lastSignature)return;
    lastSignature=signature;
    el('eventNumber').textContent=active?
      String(active.eventNumberLabel||Number(active.eventId)||Number(active.order)||'—'):'—';
    el('fixtureTitle').textContent=active?active.eventName:'รอ Manager เลือกรายการแข่งขัน';
    el('roundLabel').textContent=active?(active.round||'รอยืนยันรอบ'):'ยังไม่เริ่ม';
    paintSchools(payload.schools,false);
    nextEvent(next);
  }
  function getSportFixture(key){
    const cached=sportCache.get(key);
    if(cached&&Date.now()-cached.savedAt<120000)return Promise.resolve(cached.data);
    if(sportPending.has(key))return sportPending.get(key);
    const promise=fetch('../api.php?action=live_sport_fixture&fixtureKey='+encodeURIComponent(key),{
      cache:'no-store',credentials:'same-origin'
    }).then(async response=>{
      if(!response.ok)throw Error('HTTP '+response.status);
      const data=await response.json();
      if(data.success!==true||!data.fixture||!Array.isArray(data.schools))
        throw Error(data.message||'ไม่พบข้อมูลภาพนักกรีฑา');
      sportCache.delete(key);
      sportCache.set(key,{savedAt:Date.now(),data});
      while(sportCache.size>6)sportCache.delete(sportCache.keys().next().value);
      return data;
    }).finally(()=>sportPending.delete(key));
    sportPending.set(key,promise);
    return promise;
  }
  function slideIdentity(fixture){
    return [fixture.eventNumberLabel||fixture.eventId||'—',fixture.eventName,fixture.round||''];
  }
  function sportSchoolName(value){
    return String(value||'').replace(/^โรงเรียน/u,'').trim()||'ไม่ระบุโรงเรียน';
  }
  function sportImageHTML(person,showName=false){
    const label=esc(person?.name||'นักกรีฑา');
    const img=person?.photo
      ?'<img loading="eager" decoding="async" src="'+esc(person.photo)+'" alt="'+label+'" referrerpolicy="no-referrer">'
      :'<div class="sport-image-placeholder" role="img" aria-label="ไม่มีรูปนักกรีฑา">ยังไม่มีรูป</div>';
    return '<div class="sport-hero-photo">'+img+
      (showName?'<span class="sport-player-name">'+label+'</span>':'')+'</div>';
  }
  function sportPhotoTiles(athletes){
    const people=athletes.slice(0,4);
    if(!people.length)return '<div class="sport-image-placeholder">ยังไม่มีรูปนักกรีฑา</div>';
    return people.map(p=>sportImageHTML(p,people.length>1)).join('');
  }
  function sportRivalCard(school){
    const people=Array.isArray(school.athletes)?school.athletes.slice(0,4):[];
    const team=Boolean(school.registered);
    const multi=people.length>1;
    const tiles=people.length?people.map(person=>person.photo
      ?'<img loading="eager" decoding="async" src="'+esc(person.photo)+'" alt="'+esc(person.name)+'" referrerpolicy="no-referrer">'
      :'<div class="sport-image-placeholder" role="img" aria-label="ไม่มีรูปนักกรีฑา">ไม่มีรูป</div>').join('')
      :'<div class="sport-image-placeholder">'+(team?'ยังไม่มีรูป':'—')+'</div>';
    const heat=(Array.isArray(school.heats)?school.heats:[]).map(Number).filter(n=>n>0&&n<100);
    const badge=heat.length?'ฮีท '+[...new Set(heat)].join(', '):'';
    const subtitle=!team?'ไม่ได้ลงรายการ':
      (people.length>1?people.length+' นักกรีฑา':people[0]?.name||'รอข้อมูลนักกรีฑา');
    return '<article class="sport-rival-card'+(team?' is-registered':' is-empty')+'">'+
      '<div class="sport-rival-photo'+(multi?' is-relay':'')+'">'+tiles+'</div>'+
      '<div class="sport-rival-copy"><strong>'+esc(sportSchoolName(school.name))+'</strong>'+
      '<small>'+esc(subtitle)+'</small>'+
      (badge?'<span class="sport-rival-tag">'+esc(badge)+'</span>':'')+
      '</div></article>';
  }
  function bindSportPhotos(){
    const root=el('sportShowcase');
    if(!root)return;
    root.querySelectorAll('img').forEach(img=>{
      img.addEventListener('error',function(){
        const fallback=document.createElement('div');
        fallback.className='sport-image-placeholder';
        fallback.textContent='ยังไม่มีรูป';
        fallback.setAttribute('role','img');
        fallback.setAttribute('aria-label','ภาพนักกรีฑาไม่สามารถแสดงได้');
        this.replaceWith(fallback);
      },{once:true});
    });
  }
  function paintSportTeams(schools,index,formerChampion){
    const input=Array.isArray(schools)?schools:[];
    // Show the confirmed former CHAMPION SCHOOL, not a falsely identified
    // "former champion student". Portraits belong to the current roster only.
    const champion=formerChampion?.school?formerChampion:null;
    const normalizeSchool=name=>sportSchoolName(name)
      .replace(/\s+/g,'').toLocaleLowerCase('th');
    const championTeam=champion?input.find(s=>
      s.registered&&normalizeSchool(s.name)===normalizeSchool(champion.school)):null;
    const populated=input.filter(s=>s.registered&&Array.isArray(s.athletes)&&s.athletes.some(a=>a.photo));
    const registered=input.filter(s=>s.registered);
    const pool=populated.length?populated:(registered.length?registered:input);
    const fallback=pool.length?pool[index%pool.length]:null;
    // If the old champion does not take part, show its school as archival
    // context with no student portrait. Never pass a different child off as a champion.
    const hero=champion?(championTeam||{name:champion.school,athletes:[],heats:[],registered:false}):fallback;
    const others=championTeam?input.filter(s=>s!==championTeam):
      !champion?input.filter(s=>s!==fallback):input;
    const people=Array.isArray(hero?.athletes)?hero.athletes.slice(0,4):[];
    const multi=people.length>1;
    const pics=el('sportHeroPhotos');
    pics.className='sport-hero-photos'+(multi?' is-multi':'')+
      (people.length===2?' is-duo':'');
    pics.innerHTML=sportPhotoTiles(people);
    el('sportHeroLabel').textContent=champion?'🏆 FORMER CHAMPION':'★ FEATURED ATHLETE';
    el('sportHeroCaptionLabel').textContent=champion
      ?'โรงเรียนแชมป์เก่า · '+champion.season
      :'ตัวแทนนักกรีฑาในรายการปัจจุบัน';
    el('sportHeroSchool').textContent=hero?sportSchoolName(hero.name):'รอข้อมูลโรงเรียน';
    el('sportHeroName').textContent=champion&&!championTeam
      ?'แชมป์เก่าไม่ได้ส่งนักกรีฑาในรายการนี้':
      people.length===1?people[0].name:
      people.length>1?people.length+' นักกรีฑาที่ลงทะเบียนในปีปัจจุบัน':
      'ยังไม่มีรูปนักกรีฑาที่ลงทะเบียน';
    const heat=Array.isArray(hero?.heats)?hero.heats.filter(n=>Number(n)>0):[];
    el('sportHeroHeat').textContent=champion?'🏆 '+champion.season:
      heat.length?'HEAT '+heat.join('–'):'ATHLETICS';
    const rivalGrid=el('sportRivalGrid');
    rivalGrid.classList.toggle('is-ten',others.length>9);
    rivalGrid.innerHTML=others.slice(0,10).map(sportRivalCard).join('')||
      '<div class="sport-loading">รอข้อมูลโรงเรียนที่เข้าร่วมแข่งขัน</div>';
    el('sportTeamCount').textContent=input.filter(s=>s.registered).length+
      ' / '+input.length+' TEAMS';
    bindSportPhotos();
  }
  function paintSportSchedule(fixtures,index){
    const total=fixtures.length;
    el('sportEventTotal').textContent=String(total);
    const entries=[];
    // A compact live program around this fixture, ordered by the existing
    // official competition sequence. No invented dates/times.
    const shown=Math.min(5,total);
    for(let j=0;j<shown;j++){
      const i=(index+j)%total,fixture=fixtures[i];
      entries.push('<div class="sport-schedule-item'+
        (j===0?' is-current':j===1?' is-next':'')+'">'+
        '<span class="sport-schedule-number">'+esc(fixture.eventNumberLabel||fixture.eventId||'—')+'</span>'+
        '<span class="sport-schedule-text"><strong>'+esc(fixture.eventName)+'</strong>'+
        '<small>'+esc(j===0?'กำลังแสดง':j===1?'รายการถัดไป':(fixture.round||'รายการแข่งขัน'))+'</small></span></div>');
    }
    el('sportScheduleList').innerHTML=entries.join('')||
      '<div class="sport-schedule-empty">ยังไม่มีรายการแข่งขัน</div>';
  }
  function sportDisplayHeader(fixture,index,total){
    el('sportEventNumber').textContent='รายการที่ '+(fixture.eventNumberLabel||fixture.eventId||'—');
    el('sportEventName').textContent=fixture.eventName||'ไม่ระบุรายการ';
    el('sportRound').textContent=fixture.round||'รอยืนยันรอบ';
    const heat=Array.isArray(fixture.heats)&&fixture.heats.length>1?
      'ฮีท '+fixture.heats.join('–'):(fixture.heat?'ฮีท '+fixture.heat:'');
    el('sportEventMeta').textContent=[fixture.category,fixture.level,
      fixture.gender,heat].filter(Boolean).join(' • ');
    el('sportSlideCounter').textContent=(index+1)+' / '+total;
    el('sportIntroNumber').textContent='EVENT '+(fixture.eventNumberLabel||fixture.eventId||'—');
    el('sportIntroTitle').textContent=fixture.eventName||'READY TO RUN';
  }
  function playSportIntro(){
    const intro=el('sportIntro'),root=el('sportShowcase');
    if(!intro||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    intro.classList.remove('is-playing');
    root.classList.remove('is-changing');
    void intro.offsetWidth; // restart CSS animation only for an actual slide change
    intro.classList.add('is-playing');
    root.classList.add('is-changing');
  }
  function sportCountdown(payload,index,elapsed,stepMs){
    const fixtures=payload.fixtures||[];
    const total=fixtures.length;
    const next=total?fixtures[(index+1)%total]:null;
    if(next)el('sportNextEvent').textContent='รายการที่ '+
      (next.eventNumberLabel||next.eventId||'—')+' • '+next.eventName;
    const within=Math.max(0,elapsed%stepMs);
    el('sportProgressFill').style.width=Math.min(100,within/stepMs*100)+'%';
    el('sportProgressTime').textContent=Math.max(0,Math.ceil((stepMs-within)/1000))+'s';
  }
  async function sportSlide(payload,index,token){
    const fixtures=payload.fixtures,fixture=fixtures[index];
    sportDisplayHeader(fixture,index,fixtures.length);
    paintSportSchedule(fixtures,index);
    playSportIntro();
    el('sportRivalGrid').innerHTML='<div class="sport-loading">กำลังเรียกข้อมูลนักกรีฑา...</div>';
    el('sportHeroPhotos').innerHTML='<div class="sport-image-placeholder">กำลังโหลดภาพนักกรีฑา</div>';
    el('sportHeroSchool').textContent='โรงเรียนที่เข้าร่วมแข่งขัน';
    el('sportHeroName').textContent='กำลังเตรียมภาพการแข่งขัน';
    try{
      const data=await getSportFixture(fixture.key);
      if(lastMode!=='sport'||token!==sportSlideToken)return;
      const signature=JSON.stringify([fixture.key,data.schools]);
      if(lastSportBoardSignature!==signature){
        paintSportTeams(data.schools,index,data.formerChampion||null);
        lastSportBoardSignature=signature;
      }
      sportRetryAt=0;
      const following=fixtures[(index+1)%fixtures.length];
      if(following&&following.key!==fixture.key)
        void getSportFixture(following.key).catch(()=>{});
    }catch(error){
      if(lastMode!=='sport'||token!==sportSlideToken)return;
      el('sportRivalGrid').innerHTML='<div class="sport-loading">ขัดข้องระหว่างโหลดภาพนักกรีฑา · กำลังลองใหม่</div>';
      el('sportHeroSchool').textContent='เชื่อมต่อข้อมูลนักกรีฑา';
      sportRetryAt=Date.now()+6000;
      sportSlideIndex=-1;
    }
  }
  function renderSportCycle(payload){
    const fixtures=Array.isArray(payload.fixtures)?
      payload.fixtures.filter(f=>f&&f.key):[];
    if(!fixtures.length){
      el('sportEventName').textContent='รอโปรแกรมการแข่งขัน';
      el('sportEventNumber').textContent='SPORT SHOWCASE';
      el('sportRound').textContent='SPORT';
      el('sportHeroSchool').textContent='ยังไม่มีรายการแข่งขัน';
      el('sportHeroPhotos').innerHTML='<div class="sport-image-placeholder">🏃</div>';
      el('sportRivalGrid').innerHTML='<div class="sport-loading">กำลังรอรายการแข่งขัน</div>';
      el('sportScheduleList').innerHTML='<div class="sport-schedule-empty">ยังไม่มีรายการแข่งขัน</div>';
      el('sportNextEvent').textContent='รอโปรแกรมการแข่งขัน';
      el('sportEventTotal').textContent='0';
      el('sportProgressFill').style.width='0%';
      return;
    }
    const stepMs=Math.max(8000,Number(payload.display?.slideSeconds||15)*1000);
    const since=Number(payload.display?.startedAtMs||0);
    const now=Date.now()+clockOffsetMs;
    const elapsed=Math.max(0,now-since);
    const index=Math.floor(elapsed/stepMs)%fixtures.length;
    sportCountdown({...payload,fixtures},index,elapsed,stepMs);
    if(index===sportSlideIndex||Date.now()<sportRetryAt)return;
    sportSlideIndex=index;
    lastSportBoardSignature='';
    const token=++sportSlideToken;
    void sportSlide({...payload,fixtures},index,token);
  }
  function render(payload){
    loaded=true;
    lastPayload=payload;
    if(Number.isFinite(Number(payload.serverNowMs)))
      clockOffsetMs=Number(payload.serverNowMs)-Date.now();
    const mode=payload.display?.mode==='sport'?'sport':'checkin';
    if(mode!==lastMode){
      lastMode=mode;
      lastSignature='';
      lastSportBoardSignature='';
      sportSlideIndex=-1;
      sportRetryAt=0;
      ++sportSlideToken; // Ignore previous-mode asynchronous responses.
      sportCache.clear();
      sportPending.clear();
      document.querySelector('.live-screen').classList.toggle('is-sport',mode==='sport');
      const showcase=el('sportShowcase');
      if(showcase)showcase.hidden=mode!=='sport';
      if(mode!=='sport'){
        el('sportIntro').classList.remove('is-playing');
        el('sportShowcase').classList.remove('is-changing');
      }
      setNextLabels(mode==='sport');
    }
    if(mode==='sport')renderSportCycle(payload);
    else renderCheckin(payload);
    renderRecentResults(payload.latestResults);
    el('connection').className='connected';
    el('connection').textContent=mode==='sport'?'● LIVE • SPORT':'● LIVE • เชื่อมต่อแล้ว';
    el('lastRefresh').textContent='อัปเดต '+stamp();
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
      if(payload.success!==true)throw Error(payload.message||'เชื่อมต่อไม่สำเร็จ');
      render(payload);
    }catch(error){
      el('connection').className='';
      el('connection').textContent='● การเชื่อมต่อขัดข้อง';
      el('lastRefresh').textContent=loaded?'แสดงข้อมูลล่าสุด':'โหลดข้อมูลไม่ได้';
    }finally{requestBusy=false;}
  }
  el('sportFullScreen').addEventListener('click',async()=>{
    try{
      if(document.fullscreenElement)await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
    }catch(error){}
  });
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
  // The progress strip and fixture transitions stay fluid between API polls.
  setInterval(()=>{
    if(lastMode==='sport'&&lastPayload&&document.visibilityState==='visible')
      renderSportCycle(lastPayload);
  },550);
})();
