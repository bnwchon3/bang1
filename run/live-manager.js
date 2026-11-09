/* Manager/Admin: checkbox-based athlete check-in for the active competition. */
(function(){
  'use strict';
  const el=id=>document.getElementById(id);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let snapshot=null,busy=false,loading=false,filter='',lastActionAt=0,lastRosterHash='',lastNextRosterHash='',lastOptionHash='',pendingFixtureKey=null,switchInFlight=false;
  let fixtureItems=[],fixtureMenuOpen=false,fixtureQuery='',fixtureRound='all';
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
    const number=f.eventNumberLabel?'รายการที่ '+f.eventNumberLabel+' · ':'';
    const heat=Array.isArray(f.heats)&&f.heats.length>1?
      'ฮีท '+f.heats.join('–'):(f.heat?'ฮีท '+f.heat:'');
    return number+[f.eventName,f.category,f.level,f.gender,heat].filter(Boolean).join(' · ');
  }
  function isFinalFixture(f){
    return /^(รอบชิง|ชิงชนะเลิศ)/.test(String(f?.round||''));
  }
  function fixtureTitle(f){
    return 'รายการที่ '+(f.eventNumberLabel||f.eventId||'—')+' · '+(f.eventName||'ไม่ระบุรายการ');
  }
  function fixtureDetails(f){
    const heat=Array.isArray(f.heats)&&f.heats.length>1
      ?'ฮีท '+f.heats.join('–'):(f.heat?'ฮีท '+f.heat:'');
    return [f.category,f.level,f.gender,heat].filter(Boolean).join(' · ');
  }
  function updateFixtureTrigger(){
    const trigger=el('bang1FixtureTrigger');
    if(!trigger)return;
    const selected=fixtureItems.find(f=>f.key===el('bang1ActiveFixture')?.value)||
      snapshot?.active||null;
    el('bang1FixtureCurrentTitle').textContent=selected?
      fixtureTitle(selected):'เลือกรายการแข่งขัน';
    el('bang1FixtureCurrentDetail').textContent=selected?
      [fixtureDetails(selected),selected.round].filter(Boolean).join(' · '):
      'ค้นหาและเลือกรายการแข่งขันเพื่อเปลี่ยนจอ Live ทันที';
    trigger.classList.toggle('is-final',Boolean(selected&&isFinalFixture(selected)));
    trigger.classList.toggle('has-selection',Boolean(selected));
  }
  function positionFixtureMenu(){
    if(!fixtureMenuOpen)return;
    const trigger=el('bang1FixtureTrigger'),menu=el('bang1FixturePopover');
    if(!trigger||!menu)return;
    const rect=trigger.getBoundingClientRect();
    const vw=window.innerWidth,vh=window.innerHeight;
    const width=Math.min(1000,Math.max(0,vw-16),Math.max(rect.width,260));
    const left=Math.min(Math.max(8,rect.left),Math.max(8,vw-width-8));
    const height=Math.max(220,Math.min(620,Math.round(vh*.74),vh-16));
    const below=vh-rect.bottom-10,above=rect.top-10;
    let top;
    if(below>=Math.min(320,height))top=rect.bottom+8;
    else if(above>=Math.min(320,height))top=rect.top-height-8;
    else top=Math.max(8,Math.min(rect.bottom+8,vh-height-8));
    menu.style.left=left+'px';
    menu.style.top=top+'px';
    menu.style.width=width+'px';
    menu.style.maxHeight=Math.min(height,vh-top-8)+'px';
  }
  function renderFixtureOptions(){
    const list=el('bang1FixtureList'),footer=el('bang1FixtureResults');
    if(!list||!footer)return;
    const query=fixtureQuery.toLocaleLowerCase('th').replace(/\s+/g,'');
    const visible=fixtureItems.filter(f=>{
      if(fixtureRound==='final'&&!isFinalFixture(f))return false;
      if(fixtureRound==='prelim'&&isFinalFixture(f))return false;
      const haystack=[fixtureTitle(f),fixtureDetails(f),f.round||'']
        .join(' ').toLocaleLowerCase('th').replace(/\s+/g,'');
      return !query||haystack.includes(query);
    });
    list.replaceChildren();
    if(!visible.length){
      const empty=document.createElement('p');
      empty.className='bang1-fixture-no-results';
      empty.textContent='ไม่พบรายการแข่งขันที่ตรงกับคำค้นหา';
      list.appendChild(empty);
    }
    const selected=el('bang1ActiveFixture')?.value||'';
    visible.forEach((f)=>{
      const option=document.createElement('button');
      option.type='button';
      option.className='bang1-fixture-option'+
        (isFinalFixture(f)?' is-final':'')+
        (selected===f.key?' is-selected':'');
      option.setAttribute('role','option');
      option.setAttribute('aria-selected',selected===f.key?'true':'false');
      option.dataset.key=f.key;
      option.innerHTML=
        '<span class="bang1-fixture-number">'+esc(f.eventNumberLabel||f.eventId||'—')+'</span>'+
        '<span class="bang1-fixture-option-copy">'+
          '<strong>'+esc(f.eventName||'ไม่ระบุรายการ')+'</strong>'+
          '<small>'+esc(fixtureDetails(f))+'</small>'+
        '</span>'+
        '<span class="bang1-fixture-option-meta">'+
          '<span class="bang1-fixture-round">'+(isFinalFixture(f)?'🏆 รอบชิง':esc(f.round||'รอยืนยันรอบ'))+'</span>'+
          '<small>'+Number(f.checked||0)+'/'+Number(f.total||0)+' คน</small>'+
        '</span>';
      option.addEventListener('click',()=>{
        if(el('bang1ActiveFixture').value!==f.key){
          el('bang1ActiveFixture').value=f.key;
          el('bang1ActiveFixture').dispatchEvent(new Event('change',{bubbles:true}));
        }
        updateFixtureTrigger();
        closeFixtureMenu(true);
      });
      list.appendChild(option);
    });
    footer.textContent='แสดง '+visible.length+' จาก '+fixtureItems.length+' รายการแข่งขัน · รอบชิงพื้นสีเขียวอ่อน';
    el('bang1FixturePopover').querySelectorAll('[data-round]').forEach(btn=>{
      const active=btn.dataset.round===fixtureRound;
      btn.classList.toggle('is-active',active);
      btn.setAttribute('aria-pressed',active?'true':'false');
    });
  }
  function openFixtureMenu(){
    const trigger=el('bang1FixtureTrigger'),menu=el('bang1FixturePopover');
    if(!menu||!trigger||!activePage())return;
    if(menu.parentElement!==document.body)document.body.appendChild(menu);
    fixtureMenuOpen=true;
    fixtureQuery='';
    fixtureRound='all';
    el('bang1FixtureSearch').value='';
    menu.hidden=false;
    trigger.setAttribute('aria-expanded','true');
    renderFixtureOptions();
    positionFixtureMenu();
    const selected=menu.querySelector('.bang1-fixture-option.is-selected');
    if(selected)selected.scrollIntoView({block:'nearest'});
    if(window.matchMedia('(pointer: fine)').matches)
      el('bang1FixtureSearch').focus({preventScroll:true});
  }
  function closeFixtureMenu(restoreFocus=false){
    if(!fixtureMenuOpen)return;
    fixtureMenuOpen=false;
    const menu=el('bang1FixturePopover'),trigger=el('bang1FixtureTrigger');
    if(menu)menu.hidden=true;
    if(trigger){
      trigger.setAttribute('aria-expanded','false');
      if(restoreFocus)trigger.focus({preventScroll:true});
    }
  }
  function handleFixtureKeyboard(event){
    if(!fixtureMenuOpen)return;
    if(event.key==='Escape'){
      event.preventDefault();closeFixtureMenu(true);return;
    }
    if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
    const buttons=[...el('bang1FixtureList').querySelectorAll('button[role="option"]')];
    if(!buttons.length)return;
    event.preventDefault();
    const focused=buttons.indexOf(document.activeElement);
    const next=event.key==='Home'?0:event.key==='End'?buttons.length-1:
      event.key==='ArrowDown'?(focused<0?0:(focused+1)%buttons.length):
      (focused<0?buttons.length-1:(focused-1+buttons.length)%buttons.length);
    buttons[next].focus({preventScroll:true});
    buttons[next].scrollIntoView({block:'nearest'});
  }
  function athleteCard(athlete,regId,slotsFull=false){
    const checked=Boolean(athlete.checkedAt);
    const disabled=slotsFull&&!checked;
    const photo=athlete.photo
      ?'<img src="'+esc(athlete.photo)+'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=&quot;none&quot;">'
      :'<span class="bang1-avatar-fallback" aria-hidden="true">🏃</span>';
    return '<label class="bang1-athlete-row'+(checked?' is-checked':'')+'">'+
      '<input type="checkbox" data-reg="'+Number(regId)+'" data-athlete="'+esc(athlete.athleteKey)+'"'+
        (checked?' checked':'')+(disabled?' disabled':'')+
        ' aria-label="รายงานตัว '+esc(athlete.name)+'">'+
      '<span class="bang1-athlete-photo">'+photo+'</span>'+
      '<span class="bang1-athlete-info"><strong>'+esc(athlete.name)+'</strong>'+
       '<small>'+(checked?'✓ รายงานตัวแล้ว '+esc(athlete.checkedAt):
         disabled?'เต็มจำนวนแล้ว · ยกเลิกชื่อเดิมก่อน':'ยังไม่รายงานตัว')+'</small></span>'+
      '<span class="bang1-athlete-status'+(checked?' confirmed':'')+'">'+(checked?'✓':'○')+'</span>'+
      '</label>';
  }
  function teamMarkup(team){
    const limit=Number(team.totalAthletes)||1;
    const checked=Number(team.checkedAthletes)||0;
    const full=checked>=limit;
    const over=Number(team.excessCheckins)||0;
    return '<section class="bang1-roster-school">'+
      '<header><div><strong>'+esc(team.school)+'</strong><small>ใบสมัคร #'+Number(team.regId)+
      (team.heat?' · ฮีท '+Number(team.heat):'')+
      (team.lane?' · ลู่ '+Number(team.lane):'')+'</small></div>'+
      '<span>'+checked+' / '+limit+' คน</span></header>'+
      (over?'<div class="bang1-oversubscribed">⚠️ มีการติ๊กเกินจำนวนที่กำหนด '+over+' คน กรุณาตรวจสอบ</div>':'')+
      '<div class="bang1-athlete-list">'+team.athletes.map(a=>athleteCard(a,team.regId,full)).join('')+'</div>'+
      '</section>';
  }
  function renderRoster(force=false){
    const area=el('bang1CheckinRoster');
    if(!area)return;
    const teams=Array.isArray(snapshot?.teams)?snapshot.teams:[];
    const active=snapshot?.active;
    const signature=JSON.stringify([active?.key||'',teams,filter]);
    if(!force&&signature===lastRosterHash)return;
    lastRosterHash=signature;
    const previousScroll=area.closest('.main')?.scrollTop??null;
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
    area.innerHTML=visible.map(teamMarkup).join('');
    // Keep the Manager at the same scroll position after a successful check-in.
    if(previousScroll!==null&&area.closest('.main'))area.closest('.main').scrollTop=previousScroll;
  }
  function renderNextRoster(force=false){
    const area=el('bang1NextRoster');
    if(!area)return;
    const next=snapshot?.active?(snapshot?.next||[])[0]:null;
    const teams=Array.isArray(snapshot?.nextTeams)?snapshot.nextTeams:[];
    const signature=JSON.stringify([next?.key||'',teams]);
    if(!force && signature===lastNextRosterHash)return;
    lastNextRosterHash=signature;
    const card=el('bang1NextCard');
    const heading=el('bang1NextFixtureName');
    const count=el('bang1NextCheckinCount');
    if(!next){
      card.hidden=true;
      heading.textContent='ไม่มีรายการถัดไป';
      count.textContent='—';
      area.innerHTML='<div class="bang1-roster-empty">ยังไม่มีรายการถัดไป</div>';
      return;
    }
    card.hidden=false;
    heading.textContent=fixtureName(next);
    count.textContent=Number(next.checked)+' / '+Number(next.total)+' คน';
    area.innerHTML=teams.length?teams.map(teamMarkup).join(''):
      '<div class="bang1-roster-empty">ยังไม่มีนักกรีฑาสมัครในรายการถัดไป</div>';
  }
  function render(data){
    snapshot=data;
    const select=el('bang1ActiveFixture');
    const selected=select.value;
    const fixtures=Array.isArray(data.fixtures)?data.fixtures:[];
    fixtureItems=fixtures;
    const optionHash=JSON.stringify(fixtures.map(f=>[f.key,f.eventNumberLabel,f.eventName,f.category,f.level,f.gender,f.heat,f.heats,f.round]));
    if(optionHash!==lastOptionHash){
      lastOptionHash=optionHash;
      select.replaceChildren(new Option('เลือกรายการแข่งขัน/ฮีท',''));
      fixtures.forEach((f,i)=>select.appendChild(new Option(
        fixtureName(f),f.key)));
      }
    if(!switchInFlight)select.value=data.active?.key||'';
    updateFixtureTrigger();
    if(fixtureMenuOpen)renderFixtureOptions();
    const active=data.active;
    el('bang1LiveActiveName').textContent=active?fixtureName(active):'ยังไม่ได้เปิดรายการ Active';
    el('bang1LiveActiveCount').textContent=active?
      'รายงานตัว '+active.checked+'/'+active.total+' คน · '+active.teams+' โรงเรียนที่ลงทะเบียน':
      'เลือกการแข่งขันแล้วกดแสดงรายการบนจอ Live';
    el('bang1ActiveEnd').disabled=!active;
    renderRoster();
    renderNextRoster();
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
    finally{busy=false;if(pendingFixtureKey!==null)void flushSelection()}
  }
  // A selection immediately changes the public screen. Repeated quick changes
  // are serialized, with only the user's latest selection retained.
  async function flushSelection(){
    if(busy||switchInFlight||pendingFixtureKey===null)return;
    switchInFlight=true;busy=true;
    try{
      while(pendingFixtureKey!==null){
        const key=pendingFixtureKey;
        pendingFixtureKey=null;
        message('กำลังเปลี่ยนรายการบนจอ Live...','info');
        try{
          await request('live_select',{fixtureKey:key});
          lastActionAt=Date.now();
        }catch(error){
          message(error.message||'เปลี่ยนรายการไม่สำเร็จ','error');
        }
      }
      const state=await request('live_manager_data');
      render(state);
      el('bang1ActiveFixture').value=state.active?.key||'';
      updateFixtureTrigger();
      if(state.active)message('✓ แสดงรายการ '+fixtureName(state.active)+' บนจอ Live แล้ว','success');
    }catch(error){
      message(error.message||'อ่านสถานะจอ Live ไม่สำเร็จ','error');
    }finally{
      busy=false;switchInFlight=false;
      if(pendingFixtureKey!==null)void flushSelection();
    }
  }
  function setup(){
    const page=el('page-checkin');if(!page)return;
    el('bang1ActiveFixture').addEventListener('change',event=>{
      pendingFixtureKey=event.target.value||'';
      void flushSelection();
    });
    const menu=el('bang1FixturePopover'),trigger=el('bang1FixtureTrigger');
    trigger.addEventListener('click',()=>{
      if(fixtureMenuOpen)closeFixtureMenu();
      else openFixtureMenu();
    });
    el('bang1FixtureClose').addEventListener('click',()=>closeFixtureMenu(true));
    el('bang1FixtureSearch').addEventListener('input',event=>{
      fixtureQuery=event.target.value;
      renderFixtureOptions();
    });
    menu.querySelectorAll('[data-round]').forEach(button=>{
      button.addEventListener('click',()=>{
        fixtureRound=button.dataset.round;
        renderFixtureOptions();
        el('bang1FixtureSearch').focus({preventScroll:true});
      });
    });
    menu.addEventListener('keydown',handleFixtureKeyboard);
    document.addEventListener('pointerdown',event=>{
      if(fixtureMenuOpen&&!menu.contains(event.target)&&!trigger.contains(event.target))
        closeFixtureMenu();
    });
    window.addEventListener('resize',positionFixtureMenu);
    document.addEventListener('scroll',positionFixtureMenu,true);
    // The popover is rendered in body to prevent nested cards from clipping it.
    // Close it when users move away from the check-in page.
    const originalObserver=new MutationObserver(()=>{
      if(!activePage())closeFixtureMenu();
    });
    originalObserver.observe(page,{attributes:true,attributeFilter:['class']});

    el('bang1ActiveEnd').addEventListener('click',()=>{
      if(confirm('ยืนยันปิดรายการ Active บนจอ Live?')){
        pendingFixtureKey='';
        void flushSelection();
      }
    });
    el('bang1CheckinRefresh').addEventListener('click',()=>refresh(true));
    el('bang1AthleteSearch').addEventListener('input',e=>{
      filter=e.target.value;renderRoster(true);
    });
    const onAthleteChange=async e=>{
      const box=e.target.closest('input[type="checkbox"][data-athlete]');
      if(!box)return;
      if(busy){
        // Do not leave an unsubmitted checkbox visually checked if another
        // athlete's write is still in flight.
        box.checked=!box.checked;
        message('กำลังบันทึกรายชื่อก่อนหน้า กรุณาติ๊กอีกครั้ง','info');
        return;
      }
      const regId=Number(box.dataset.reg);
      const athleteKey=box.dataset.athlete;
      const checked=box.checked;
      box.disabled=true;
      message('กำลังบันทึกการรายงานตัว...','info');
      const succeeded=await update('live_runner_set',{regId,athleteKey,checked});
      if(!succeeded){
        box.checked=!checked;box.disabled=false;
      }
    };
    ['bang1CheckinRoster','bang1NextRoster'].forEach(id=>{
      el(id).addEventListener('change',onAthleteChange);
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
