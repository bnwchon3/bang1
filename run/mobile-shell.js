/* Compact mobile/tablet navigation and viewport-safe input focus.
   Controls only layout/scroll; does not rerender or overwrite form values. */
(function(){
  'use strict';
  const media=window.matchMedia('(max-width:1199px)');
  let mobileButton=null,backdrop=null,sidebar=null,main=null,lastHeight=0;
  let menu=null,logoutButton=null,logoutDock=null;
  let frame=0,focusTimer=0;
  function isMobile(){return media.matches}
  function editing(el){
    if(!el||!(el instanceof Element)||el.disabled||el.readOnly)return false;
    if(el.matches('textarea,select,[contenteditable="true"]'))return true;
    if(!el.matches('input'))return false;
    return !['hidden','checkbox','radio','file','button','submit','reset','range','color'].includes(el.type);
  }
  function updateVisibleHeight(){
    const keyboardField=isMobile()&&editing(document.activeElement);
    document.body.classList.toggle('bang1-keyboard-open',keyboardField);
    if(!keyboardField){
      // Browser toolbar changes must not trigger a full-screen relayout.
      document.documentElement.style.removeProperty('--bang1-visible-height');
      lastHeight=0;
      return;
    }
    // Only the INNER content/modal viewport tracks the soft keyboard.
    // The outer application is locked to 100svh by mobile-shell.css.
    const viewport=window.visualViewport;
    const value=Math.round(viewport?.height||window.innerHeight);
    if(value>0&&Math.abs(value-lastHeight)>2){
      lastHeight=value;
      document.documentElement.style.setProperty('--bang1-visible-height',value+'px');
    }
  }
  function findScroller(input){
    let node=input.parentElement;
    while(node&&node!==document.body){
      const style=window.getComputedStyle(node);
      if(node.scrollHeight>node.clientHeight+2 && /(auto|scroll)/.test(style.overflowY))return node;
      node=node.parentElement;
    }
    return main;
  }
  function moveOnlyInnerScroller(){
    if(!isMobile()||!editing(document.activeElement))return;
    const field=document.activeElement;
    const scroller=findScroller(field);
    if(!scroller)return;
    const fieldRect=field.getBoundingClientRect();
    const frameRect=scroller.getBoundingClientRect();
    const top=frameRect.top+(scroller===main?66:8);
    const bottom=frameRect.bottom-18;
    if(fieldRect.bottom>bottom){
      scroller.scrollTop+=Math.ceil(fieldRect.bottom-bottom);
    }else if(fieldRect.top<top){
      scroller.scrollTop-=Math.ceil(top-fieldRect.top);
    }
  }
  function scheduleViewport(){
    cancelAnimationFrame(frame);
    frame=requestAnimationFrame(()=>{
      updateVisibleHeight();
      moveOnlyInnerScroller();
    });
  }
  function closeMenu(returnFocus=false){
    if(!sidebar||!backdrop||!mobileButton)return;
    document.body.classList.remove('bang1-drawer-open');
    backdrop.hidden=true;
    mobileButton.setAttribute('aria-expanded','false');
    if(isMobile()){
      sidebar.inert=true;
      sidebar.setAttribute('aria-hidden','true');
    }
    if(returnFocus&&isMobile())mobileButton.focus({preventScroll:true});
  }
  function openMenu(){
    if(!isMobile())return;
    // Don't expose the virtual keyboard beneath the navigation.
    if(editing(document.activeElement))document.activeElement.blur();
    sidebar.inert=false;
    sidebar.removeAttribute('aria-hidden');
    backdrop.hidden=false;
    document.body.classList.add('bang1-drawer-open');
    mobileButton.setAttribute('aria-expanded','true');
    const first=sidebar.querySelector('.menu button:not([hidden]):not([style*="display: none"])');
    (first||sidebar).focus({preventScroll:true});
  }
  function syncLogoutDock(){
    if(!menu||!logoutButton||!logoutDock)return;
    // Move the original button; no clone and no duplicate logout handlers.
    // Restore it inside the original menu on desktop.
    if(isMobile()&&logoutButton.parentElement!==logoutDock){
      logoutDock.appendChild(logoutButton);
    }else if(!isMobile()&&logoutButton.parentElement!==menu){
      menu.appendChild(logoutButton);
    }
  }
  function applyMedia(){
    updateVisibleHeight();
    if(!sidebar)return;
    syncLogoutDock();
    if(!isMobile()){
      closeMenu();
      sidebar.inert=false;
      sidebar.removeAttribute('aria-hidden');
    }else if(!document.body.classList.contains('bang1-drawer-open')){
      sidebar.inert=true;
      sidebar.setAttribute('aria-hidden','true');
    }
  }
  function blockNativeZoom(event){
    if(isMobile()&&event.cancelable)event.preventDefault();
  }
  function blockMultiTouchZoom(event){
    // Never cancel one-finger gestures: tables, popups and forms must scroll.
    if(isMobile()&&event.touches?.length>1&&event.cancelable)event.preventDefault();
  }
  function init(){
    main=document.querySelector('.app>.main');
    sidebar=document.querySelector('.app>.sidebar');
    if(!main||!sidebar)return;
    sidebar.id='bang1MobileSidebar';
    sidebar.tabIndex=-1;
    menu=sidebar.querySelector('.menu');
    logoutButton=document.getElementById('bang1LogoutButton');
    if(menu&&logoutButton){
      logoutDock=document.createElement('div');
      logoutDock.className='bang1-mobile-logout';
      logoutDock.setAttribute('aria-label','บัญชีผู้ใช้');
      sidebar.appendChild(logoutDock);
    }
    const bar=document.createElement('div');
    bar.className='bang1-mobile-header';
    mobileButton=document.createElement('button');
    mobileButton.type='button';
    mobileButton.id='bang1MobileMenuToggle';
    mobileButton.setAttribute('aria-label','เปิดเมนูหลัก');
    mobileButton.setAttribute('aria-expanded','false');
    mobileButton.setAttribute('aria-controls',sidebar.id);
    mobileButton.textContent='☰ เมนู';
    const title=document.createElement('span');
    title.className='bang1-mobile-header-label';
    title.textContent='ระบบจัดการกรีฑา';
    bar.append(mobileButton,title);
    main.prepend(bar);
    backdrop=document.createElement('button');
    backdrop.type='button';
    backdrop.id='bang1MobileMenuBackdrop';
    backdrop.hidden=true;
    backdrop.tabIndex=-1;
    backdrop.setAttribute('aria-label','ปิดเมนู');
    document.querySelector('.app').appendChild(backdrop);
    mobileButton.addEventListener('click',()=>{
      document.body.classList.contains('bang1-drawer-open')?closeMenu():openMenu();
    });
    backdrop.addEventListener('click',()=>closeMenu(true));
    sidebar.addEventListener('click',event=>{
      if(event.target.closest('.menu button'))closeMenu();
    });
    document.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&document.body.classList.contains('bang1-drawer-open')){
        event.preventDefault();closeMenu(true);
      }
    });
    document.addEventListener('focusin',event=>{
      if(editing(event.target)){
        closeMenu();
        scheduleViewport();
        clearTimeout(focusTimer);
        focusTimer=setTimeout(scheduleViewport,300);
      }
    });
    document.addEventListener('focusout',()=>{
      clearTimeout(focusTimer);
      focusTimer=setTimeout(scheduleViewport,140);
    });
    // iOS Safari/WebViews may ignore viewport zoom restrictions. Block
    // native page pinch while keeping one-finger panel scrolling intact.
    // Capture gestures before standalone modals or native select controls
    // can trigger the browser's pinch zoom. One-finger scrolling stays enabled.
    document.addEventListener('gesturestart',blockNativeZoom,{passive:false,capture:true});
    document.addEventListener('gesturechange',blockNativeZoom,{passive:false,capture:true});
    document.addEventListener('gestureend',blockNativeZoom,{passive:false,capture:true});
    document.addEventListener('touchstart',blockMultiTouchZoom,{passive:false,capture:true});
    document.addEventListener('touchmove',blockMultiTouchZoom,{passive:false,capture:true});
    document.addEventListener('dblclick',event=>{
      if(isMobile()&&event.target?.closest?.('#bang1StaffScoreBackdrop,#checkModalBackdrop,.mod.open')&&event.cancelable)
        event.preventDefault();
    },{passive:false,capture:true});
    document.addEventListener('wheel',event=>{
      if(isMobile()&&event.ctrlKey&&event.target?.closest?.('#bang1StaffScoreBackdrop,#checkModalBackdrop,.mod.open')&&event.cancelable)
        event.preventDefault();
    },{passive:false,capture:true});
    window.addEventListener('resize',scheduleViewport,{passive:true});
    window.addEventListener('orientationchange',scheduleViewport,{passive:true});
    window.visualViewport?.addEventListener('resize',scheduleViewport,{passive:true});
    // Ignore visualViewport scroll: iOS pans its visual viewport while typing,
    // which previously made the entire form jump on every movement.
    if(media.addEventListener)media.addEventListener('change',applyMedia);
    else media.addListener(applyMedia);
    applyMedia();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
  else init();
})();
