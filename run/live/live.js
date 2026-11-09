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
  let sportRosterElapsed=0,sportRosterStepMs=15000,sportManualRosterPage=null;

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
    return '<div class="sport-hero-photo" data-athlete-name="'+label+'">'+img+
      (showName?'<span class="sport-player-name">'+label+'</span>':'')+'</div>';
  }
  function sportPhotoTiles(athletes){
    const people=athletes.slice(0,4);
    if(!people.length)return '<div class="sport-image-placeholder">ยังไม่มีรูปนักกรีฑา</div>';
    return people.map(p=>sportImageHTML(p,people.length>1)).join('');
  }
  function sportRivalCard(school,desktopSpan=1,mobileSpan=1){
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
    const portraits=people.filter(person=>person.photo);
    const mobilePhoto=portraits.length?portraits.map((person,i)=>
      '<img class="sport-mobile-person'+(i===0?' is-active':'')+'" '+
      'src="'+esc(person.photo)+'" alt="'+esc(person.name)+'" '+
      'data-athlete-name="'+esc(person.name)+'" loading="eager" decoding="async" referrerpolicy="no-referrer">'
    ).join(''):'<div class="sport-mobile-empty">ยังไม่มีภาพนักกีฬา</div>';
    return '<article class="sport-rival-card'+(team?' is-registered':' is-empty')+'" style="--sport-span:'+desktopSpan+';--sport-mobile-span:'+mobileSpan+';">'+
      '<div class="sport-rival-photo'+(multi?' is-relay':'')+'">'+tiles+'</div>'+
      '<div class="sport-mobile-photo">'+mobilePhoto+
        (portraits.length>1?'<span class="sport-mobile-photo-step">1 / '+portraits.length+'</span>':'')+
      '</div>'+
      '<div class="sport-rival-copy"><strong>'+esc(sportSchoolName(school.name))+'</strong>'+
      '<small>'+esc(subtitle)+'</small>'+
      '<span class="sport-mobile-athlete">'+esc(portraits[0]?.name||people[0]?.name||'ยังไม่มีรายชื่อนักกีฬา')+'</span>'+
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
  // Pack only participating schools; incomplete rows grow to use all columns.
  function sportGridLayout(total,mobile=false){
    const columns=total<=1?1:total<=6?2:total<=9?3:mobile?3:4;
    const units=columns===4?12:columns===3?6:columns;
    return {columns,units,rows:Math.max(1,Math.ceil(total/columns))};
  }
  function sportGridSpan(index,total,layout){
    const finalRowCount=total%layout.columns||layout.columns;
    return layout.units/(index>=total-finalRowCount?finalRowCount:layout.columns);
  }
  // Rotate four legible portrait cards at a time on phones. Each event's
  // existing 15s cycle is divided evenly among the participating schools.
  function mobileRosterLayout(total){
    if(total<=1)return [{col:2,row:1}];
    return [{col:1,row:1},{col:1,row:1}];
  }
  function updateSportMobileRoster(elapsed=sportRosterElapsed,stepMs=sportRosterStepMs){
    const grid=el('sportRivalGrid');
    const cards=[...grid.querySelectorAll('.sport-rival-card')];
    const pageTotal=Math.max(1,Math.ceil(cards.length/2));
    const autoPage=Math.min(pageTotal-1,Math.floor((elapsed%stepMs)/(stepMs/pageTotal)));
    if(sportManualRosterPage&&Date.now()>=sportManualRosterPage.until)sportManualRosterPage=null;
    const page=sportManualRosterPage?sportManualRosterPage.page%pageTotal:autoPage;
    const signature=page+':'+cards.length;
    if(grid.dataset.mobilePage!==signature){
      const start=page*2;
      const count=Math.min(2,Math.max(0,cards.length-start));
      const layout=mobileRosterLayout(count);
      cards.forEach((card,index)=>{
        const visible=index>=start&&index<start+2;
        card.classList.toggle('is-mobile-visible',visible);
        if(visible){
          const slot=layout[index-start];
          card.style.setProperty('--sport-page-col',slot.col);
          card.style.setProperty('--sport-page-row',slot.row);
        }
      });
      grid.dataset.mobilePage=signature;
    }
    el('sportRosterPager').hidden=pageTotal<=1;
    el('sportRosterPage').textContent=(page+1)+' / '+pageTotal;
    // One actual vertical portrait per card: rotate the registered runners
    // without dividing each athlete into tiny landscape-cropped quarters.
    const tick=Math.floor((Date.now()+clockOffsetMs)/1900);
    cards.forEach((card,slot)=>{
      if(!card.classList.contains('is-mobile-visible'))return;
      const photos=[...card.querySelectorAll('.sport-mobile-person')];
      if(!photos.length)return;
      const selected=(tick+slot)%photos.length;
      photos.forEach((photo,i)=>photo.classList.toggle('is-active',i===selected));
      card.querySelector('.sport-mobile-athlete').textContent=
        photos[selected].dataset.athleteName||'นักกีฬา';
      const step=card.querySelector('.sport-mobile-photo-step');
      if(step)step.textContent=(selected+1)+' / '+photos.length;
    });
    // FEATURED: show the same portrait treatment, not four tiny tiles.
    const heroTiles=[...el('sportHeroPhotos').querySelectorAll('.sport-hero-photo')];
    const hasPortrait=heroTiles.filter(tile=>tile.querySelector('img'));
    const heroEligible=hasPortrait.length?hasPortrait:heroTiles;
    if(heroEligible.length){
      const current=heroEligible[Math.floor((Date.now()+clockOffsetMs)/2300)%heroEligible.length];
      heroTiles.forEach(tile=>tile.classList.toggle('is-mobile-active',tile===current));
      if(window.matchMedia('(max-width:900px) and (orientation:portrait)').matches){
        const name=current.dataset.athleteName;
        if(name){
          const title=el('sportHeroLabel').textContent.includes('CHAMPION');
          el('sportHeroName').textContent=(title?'นักกีฬาปัจจุบัน: ':'')+name;
        }
      }
    }
  }
  function stepSportRosterPage(direction){
    if(lastMode!=='sport')return;
    const count=el('sportRivalGrid').querySelectorAll('.sport-rival-card').length;
    const pages=Math.ceil(count/2);
    if(pages<=1)return;
    const page=Number(el('sportRivalGrid').dataset.mobilePage?.split(':')[0]||0);
    sportManualRosterPage={page:(page+direction+pages)%pages,until:Date.now()+7000};
    updateSportMobileRoster();
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
    const pool=populated.length?populated:registered;
    const fallback=pool.length?pool[index%pool.length]:null;
    // If the old champion does not take part, show its school as archival
    // context with no student portrait. Never pass a different child off as a champion.
    const hero=champion?(championTeam||{name:champion.school,athletes:[],heats:[],registered:false}):fallback;
    // The featured current school is already shown in the hero panel.
    const others=registered.filter(s=>s!==hero);
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
    const desktop=sportGridLayout(others.length);
    const mobile=sportGridLayout(others.length,true);
    rivalGrid.style.setProperty('--sport-grid-units',String(desktop.units));
    rivalGrid.style.setProperty('--sport-grid-rows',String(desktop.rows));
    rivalGrid.style.setProperty('--sport-mobile-grid-units',String(mobile.units));
    rivalGrid.style.setProperty('--sport-mobile-grid-rows',String(mobile.rows));
    rivalGrid.innerHTML=others.map((school,i)=>sportRivalCard(school,
      sportGridSpan(i,others.length,desktop),
      sportGridSpan(i,others.length,mobile))).join('')||
      '<div class="sport-loading">ยังไม่มีโรงเรียนสมัครแข่งขันในรายการนี้</div>';
    rivalGrid.dataset.mobilePage='';
    el('sportTeamCount').textContent=registered.length+' TEAMS';
    updateSportMobileRoster();
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
    sportRosterElapsed=within;sportRosterStepMs=stepMs;
    updateSportMobileRoster(within,stepMs);
  }
  async function sportSlide(payload,index,token){
    const fixtures=payload.fixtures,fixture=fixtures[index];
    sportDisplayHeader(fixture,index,fixtures.length);
    paintSportSchedule(fixtures,index);
    sportManualRosterPage=null;
    el('sportRosterPager').hidden=true;
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
      el('sportRosterPager').hidden=true;
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

  // TRACK FLASH interrupts either Live mode only for NEW Staff-saved rankings.
  // Existing records on initial load are baselined: page refresh never replays
  // all earlier score results or an entire previous competition.
  const BREAKING_DURATION_MS=15000;
  let breakingBootstrapped=false,breakingSeen=new Set(),breakingCurrent=null;
  let breakingQueued=null,breakingStarted=0,newsSoundEnabled=false,newsAudio=null;
  const soundButtons=['newsSoundLive','newsSoundSport','newsSoundBreaking'];
  function breakingIdentity(result){
    if(!result||!Array.isArray(result.places))return '';
    return JSON.stringify([result.resultId||result.eventId||0,result.eventId||0,
      result.round,result.heat,result.updatedAt,
      result.places.slice(0,3).map(x=>[x.place,x.school])]);
  }
  function officialBreakingResult(result){
    return Boolean(result && Number(result.eventId)>0 &&
      ['คัดเลือก','ชิงชนะเลิศ'].includes(result.round) &&
      Array.isArray(result.places) && result.places.some(
        p=>Number(p.place)>=1&&Number(p.place)<=3&&String(p.school||'').trim()));
  }
  function breakingTimestamp(result){
    const date=String(result?.updatedAt||'').trim();
    if(!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(date))return NaN;
    // MariaDB stores times in Asia/Bangkok (+07), not the visitor's time zone.
    return Date.parse(date.replace(' ','T')+'+07:00');
  }
  // Real, registered athlete pictures in a FIFA-style collectible player card.
  // Official results rank schools: these are representative CURRENT runners,
  // not independently verified individual medal winners.
  function breakingPortrait(person){
    if(!person?.photo){
      return '<div class="fifa-portrait-placeholder" aria-label="ยังไม่มีรูปนักกรีฑา">'+
        '<span aria-hidden="true">🏃</span><small>ยังไม่มีรูป</small></div>';
    }
    return '<img src="'+esc(person.photo)+'" alt="ภาพนักกรีฑา '+esc(person.name||'')+
      '" loading="eager" decoding="async" referrerpolicy="no-referrer">';
  }
  function breakingPlayerImages(athletes,relay){
    if(!athletes.length){
      return '<div class="fifa-player-portraits is-missing">'+
        breakingPortrait(null)+'</div>';
    }
    const photos=athletes.slice(0,relay?4:1);
    return '<div class="fifa-player-portraits '+(relay?'is-relay':'is-solo')+'">'+
      photos.map(person=>'<div class="fifa-player-photo">'+breakingPortrait(person)+
        (relay?'<span class="fifa-mini-name">'+esc(person.name||'นักกรีฑา')+'</span>':'')+
        '</div>').join('')+'</div>';
  }
  function breakingResultCard(place,relay){
    const rank=Number(place.place);
    const medals={1:'🥇',2:'🥈',3:'🥉'};
    const rankText=rank===1?'อันดับ 1':rank===2?'อันดับ 2':'อันดับ 3';
    const athletes=Array.isArray(place.athletes)?place.athletes.slice(0,relay?4:1):[];
    const caption=athletes.length===1&&!relay?athletes[0].name:
      athletes.length>0?(relay?'นักกรีฑาทีมผลัด '+athletes.length+' คน':'นักกรีฑาของโรงเรียน'):
      'ยังไม่มีรูปนักกรีฑาที่ลงทะเบียน';
    const status=rank===1?'CHAMPION':rank===2?'RUNNER-UP':'THIRD PLACE';
    return '<article class="race-breaking-card fifa-race-card race-breaking-rank-'+rank+'">'+
      '<div class="fifa-card-header">'+
        '<span class="race-breaking-medal" aria-hidden="true">'+(medals[rank]||'🏅')+'</span>'+
        '<span class="fifa-card-place">'+rankText+'</span>'+
        '<span class="fifa-card-diamond" aria-hidden="true">✦</span>'+
      '</div>'+
      breakingPlayerImages(athletes,relay)+
      '<div class="fifa-card-info">'+
        '<strong class="fifa-card-school">'+esc(place.school)+'</strong>'+
        '<span class="fifa-card-athlete">'+esc(caption)+'</span>'+
        '<span class="fifa-card-status">'+status+'</span>'+
      '</div>'+
      '</article>';
  }
  function repairBreakingImages(){
    const root=el('breakingWinners');
    if(!root)return;
    root.querySelectorAll('.fifa-player-photo img').forEach(img=>{
      img.addEventListener('error',function(){
        const fallback=document.createElement('div');
        fallback.className='fifa-portrait-placeholder';
        fallback.innerHTML='<span aria-hidden="true">🏃</span><small>ยังไม่มีรูป</small>';
        fallback.setAttribute('aria-label','ไม่สามารถแสดงภาพนักกรีฑา');
        this.replaceWith(fallback);
      },{once:true});
    });
  }
  function breakingDecorations(finalRound){
    const container=el('breakingConfetti');
    if(!container)return;
    container.innerHTML=finalRound?
      Array.from({length:32},(_,i)=>{
        const x=(i*37+13)%100,delay=((i*13)%23)/10,duration=2.5+(i%6)*.42;
        return '<i class="race-confetti" style="--confetti-x:'+x+
          '%;--confetti-delay:'+delay+'s;--confetti-duration:'+duration+
          's;--confetti-hue:'+(i%3)+'" aria-hidden="true"></i>';
      }).join(''):'';
  }
  function showBreaking(result){
    if(!officialBreakingResult(result))return;
    breakingCurrent=result;breakingStarted=Date.now();
    const finalRound=result.round==='ชิงชนะเลิศ';
    const root=el('raceBreaking');
    root.hidden=false;
    root.classList.remove('is-active','is-final','is-prelim');
    root.classList.add(finalRound?'is-final':'is-prelim');
    el('breakingTitle').textContent=finalRound?'🏆 VICTORY MOMENT':'⚡ TRACK FLASH';
    el('breakingSubtitle').textContent=finalRound?'CHAMPIONS PODIUM • ขอแสดงความยินดี':
      'ROAD TO THE FINALS • ประกาศผู้ผ่านเข้ารอบ';
    el('breakingEvent').textContent=result.eventName;
    el('breakingRound').textContent=(finalRound?'รอบชิงชนะเลิศ':'รอบคัดเลือก')+
      (Number(result.heat)>0?' • ฮีท '+Number(result.heat):'');
    const places=result.places.filter(p=>[1,2,3].includes(Number(p.place))&&p.school);
    // The center podium gets the true first place; missing scores are never
    // filled with invented athletes or schools.
    const ordered=finalRound?
      [2,1,3].flatMap(rank=>places.filter(p=>Number(p.place)===rank)):places;
    const winners=el('breakingWinners');
    winners.className='race-breaking-winners '+(finalRound?'is-podium':'is-qualifiers');
    const relay=/ผลัด/.test(String(result.eventName||''))||
      /ผลัด/.test(String(result.category||''));
    winners.innerHTML=ordered.map(place=>breakingResultCard(place,relay)).join('');
    repairBreakingImages();
    el('breakingMessage').textContent=finalRound?
      'สุดยอดความสำเร็จ • ขอแสดงความยินดีกับโรงเรียนที่ได้รับรางวัล':
      'TOP 3 • 3 อันดับแรกของฮีทนี้ได้รับการประกาศเข้ารอบต่อไป';
    el('breakingFooterText').textContent=finalRound?
      'OFFICIAL RESULTS • CHAMPIONS CELEBRATION':
      'OFFICIAL RESULTS • QUALIFYING ROUND';
    el('breakingLiveStatus').textContent=finalRound?'🏆 ผลการแข่งขันรอบชิง':
      '⚡ ผลการแข่งขันรอบคัดเลือก';
    breakingDecorations(finalRound);
    // Restart transition only on a genuinely new saved result.
    void root.offsetWidth;
    root.classList.add('is-active');
    updateBreakingClock();
    if(newsSoundEnabled)playNewsFanfare(finalRound);
  }
  function hideBreaking(){
    const root=el('raceBreaking');
    root.classList.remove('is-active','is-prelim','is-final');
    root.hidden=true;
    breakingCurrent=null;
    breakingStarted=0;
    if(breakingQueued){
      const next=breakingQueued;breakingQueued=null;
      // Do not show stale queued results after the projector tab was hidden.
      if(Date.now()+clockOffsetMs-breakingTimestamp(next)<45000)showBreaking(next);
    }
  }
  function updateBreakingClock(){
    if(!breakingCurrent)return;
    const elapsed=Date.now()-breakingStarted;
    if(elapsed>=BREAKING_DURATION_MS){hideBreaking();return;}
    const remaining=Math.max(0,BREAKING_DURATION_MS-elapsed);
    el('breakingProgressFill').style.width=(remaining/BREAKING_DURATION_MS*100)+'%';
    el('breakingRemaining').textContent=String(Math.ceil(remaining/1000));
  }
  function watchOfficialBreakingResults(results){
    const valid=Array.isArray(results)?results.filter(officialBreakingResult):[];
    if(!breakingBootstrapped){
      breakingBootstrapped=true;
      valid.forEach(result=>breakingSeen.add(breakingIdentity(result)));
      // An already open Live screen must never replay older final scores.
      return;
    }
    const freshlySaved=[];
    const now=Date.now()+clockOffsetMs;
    for(const result of valid){
      const key=breakingIdentity(result);
      if(!key||breakingSeen.has(key))continue;
      breakingSeen.add(key);
      const when=breakingTimestamp(result);
      if(Number.isFinite(when)&&when<=now+5000&&now-when<=30000)
        freshlySaved.push(result);
    }
    if(breakingSeen.size>160){
      const recent=[...breakingSeen].slice(-80);
      breakingSeen=new Set(recent);
    }
    if(!freshlySaved.length)return;
    // The latest committed result wins when multiple Staff submissions arrive
    // during a single 3-second poll. Do not interrupt one animation mid-slide.
    const newest=freshlySaved[0];
    if(breakingCurrent)breakingQueued=newest;
    else showBreaking(newest);
  }
  function updateNewsAudioButtons(){
    soundButtons.forEach(id=>{
      const button=el(id);
      if(!button)return;
      button.setAttribute('aria-pressed',newsSoundEnabled?'true':'false');
      button.textContent=newsSoundEnabled?(id==='newsSoundBreaking'?'🔊 เสียงเปิดอยู่':'🔊 เสียง'):
        (id==='newsSoundBreaking'?'🔇 เปิดเสียง':'🔇 เสียง');
      button.title=newsSoundEnabled?'ปิดทำนองประกาศผล':
        'กดเปิดเสียงหนึ่งครั้งเพื่อฟังทำนองประกาศผลการแข่งขัน';
    });
  }
  function playNewsFanfare(finalRound){
    if(!newsSoundEnabled||!newsAudio)return;
    try{
      if(newsAudio.state!=='running'){
        void newsAudio.resume().catch(()=>{});
        if(newsAudio.state!=='running')return;
      }
      // Original brief 2.4s victory jingle synthesized locally. No recording,
      // streaming file, copyrighted song, or third-party audio resource.
      const sequence=finalRound?
        [[0,523,.22],[.24,659,.22],[.48,784,.25],[.78,1046,.41],
         [1.28,784,.19],[1.50,988,.19],[1.75,1046,.5]]:
        [[0,523,.19],[.2,587,.19],[.4,784,.25],[.72,880,.3],
         [1.08,784,.18],[1.28,1046,.46]];
      const start=newsAudio.currentTime+.025;
      for(const [offset,freq,duration] of sequence){
        const osc=newsAudio.createOscillator(),gain=newsAudio.createGain();
        osc.type='triangle';
        osc.frequency.setValueAtTime(freq,start+offset);
        gain.gain.setValueAtTime(.0001,start+offset);
        gain.gain.exponentialRampToValueAtTime(.085,start+offset+.026);
        gain.gain.exponentialRampToValueAtTime(.0001,start+offset+duration);
        osc.connect(gain);gain.connect(newsAudio.destination);
        osc.start(start+offset);osc.stop(start+offset+duration+.02);
      }
    }catch(error){
      console.warn('Track Flash sound unavailable',error);
    }
  }
  function toggleBreakingSound(){
    if(!newsSoundEnabled){
      try{
        const AudioCtor=window.AudioContext||window.webkitAudioContext;
        if(!AudioCtor)throw Error('AudioContext unavailable');
        if(!newsAudio)newsAudio=new AudioCtor();
        newsSoundEnabled=true;
        void newsAudio.resume().catch(()=>{});
      }catch(error){newsSoundEnabled=false}
    }else{
      newsSoundEnabled=false;
    }
    updateNewsAudioButtons();
    if(newsSoundEnabled&&breakingCurrent)
      playNewsFanfare(breakingCurrent.round==='ชิงชนะเลิศ');
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
        sportManualRosterPage=null;
        el('sportIntro').classList.remove('is-playing');
        el('sportShowcase').classList.remove('is-changing');
      }
      setNextLabels(mode==='sport');
    }
    if(mode==='sport')renderSportCycle(payload);
    else renderCheckin(payload);
    renderRecentResults(payload.latestResults);
    watchOfficialBreakingResults(payload.latestResults);
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
  soundButtons.forEach(id=>el(id)?.addEventListener('click',toggleBreakingSound));
  updateNewsAudioButtons();
  setInterval(updateBreakingClock,120);
  el('sportRosterPrev').addEventListener('click',()=>stepSportRosterPage(-1));
  el('sportRosterNext').addEventListener('click',()=>stepSportRosterPage(1));
  let sportSwipeStart=null;
  el('sportRivalGrid').addEventListener('touchstart',e=>{
    sportSwipeStart=e.changedTouches[0]?.clientX??null;
  },{passive:true});
  el('sportRivalGrid').addEventListener('touchend',e=>{
    if(sportSwipeStart===null)return;
    const delta=e.changedTouches[0].clientX-sportSwipeStart;
    sportSwipeStart=null;
    if(Math.abs(delta)>45)stepSportRosterPage(delta<0?1:-1);
  },{passive:true});
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
