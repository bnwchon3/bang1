'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
const load=p=>fs.readFileSync(path.join(root,p),'utf8');
const js=load('run/live/live.js'),html=load('run/live/index.php'),
      css=load('run/school-branding.css'),manager=load('run/admin-tabs.js'),
      api=load('run/live-api.php');
assert(js.includes('function schoolFlag('),'Flag renderer must exist');
assert(!js.includes("schoolFlag(school.logo,'heading')"),'Check-in school header must be text only');
assert(js.includes("schoolFlag(school.logo,'empty-body')"),'Check-in body shows large flag when no check-in');
assert(js.includes("schoolFlag(school.logo,'rival')"),'Sport rivals use logos');
assert(js.includes("schoolFlag(hero?.logo,'hero')"),'Sport Featured School uses logos');
assert(js.includes("schoolFlag(place.logo,'medal')"),'Breaking podium uses logos');
assert(html.includes('id="sportHeroFlag"'),'Hero logo slot exists');
assert(html.includes('school-branding.css?v='),'Public page loads flag animation CSS');
assert(manager.includes("admin_school_logo_upload"),'Admin calls its upload route');
assert(manager.includes('new FormData()'),'Admin sends a binary file');
assert(api.includes("'logo'=>school_logo_for"),'Live JSON adds logos to schools/results');
assert(css.includes('object-fit:contain'),'Do not crop or distort school crests');
assert(css.includes('prefers-reduced-motion'),'Honor reduced-motion accessibility');
assert(!css.includes('animation:school-flag-wave'),'School emblems must not wave or skew');
assert(css.includes('.waiting-runner'),'Waiting runner has a styled vector icon');
assert(js.includes('class="waiting-runner"'),'Runner icon renders for awaiting check-in');
assert(manager.includes('admin_school_order_save'),'Admin can persist projector school positions');
assert(api.includes('live_configured_school_order'),'Public LIVE reads configured school positions');
assert(css.includes('.race-breaking .school-flag-medal'),'Medal cards use well-positioned flags');
console.log('PASS flat school crests, running check-in status, LIVE order and all broadcast cards');


// The three Check-in states must use the LIVE checked-in list, not just
// registration presence. Test the exact renderer without DOM or server data.
const vm=require('node:vm');
const esc=x=>String(x??'').replace(/[&<>"']/g,ch=>({
  '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
})[ch]);
const state={esc};
vm.createContext(state);
const functionsStart=js.indexOf('  function athleteCard(person,sport=false){');
const errorHandler=js.indexOf('  document.addEventListener(',functionsStart);
const schoolStart=js.indexOf('  function schoolCell(school,sport=false){');
const schoolEnd=js.indexOf('  function paintSchools(',schoolStart);
assert(functionsStart>=0&&errorHandler>functionsStart&&schoolStart>0&&schoolEnd>schoolStart,
  'School and flag renderers must be discoverable');
vm.runInContext(js.slice(functionsStart,errorHandler)+js.slice(schoolStart,schoolEnd),state);
const registered={
 name:'โรงเรียนบ้านนาวัง',registered:true,heats:[1],athletes:[],
 logo:'/bang1/run/uploads/school-logos/school-1-0123456789abcdef01234567.png'
};
const waiting=state.schoolCell(registered);
assert(waiting.includes('school-empty-state is-waiting'),'Registered school without check-in has a waiting state');
assert(waiting.includes('รอรายงานตัว'),'Waiting message is shown');
assert(waiting.includes('waiting-runner'),'Waiting school visibly displays a runner pictogram');
assert(waiting.includes('school-flag-empty-body'),'School logo is large in the EMPTY content area');
assert(!waiting.slice(waiting.indexOf('<header'),waiting.indexOf('</header>')).includes('school-flag'),
  'Header must never have a school crest');
const noEntry=state.schoolCell({...registered,registered:false,heats:[]});
assert(noEntry.includes('school-empty-state is-no-entry'),'No entry is visually distinct');
assert(noEntry.includes('ไม่ได้ส่งแข่ง'),'A non-entered school must say it did not enter');
assert(noEntry.includes('school-flag-empty-body'),'No entry must KEEP the logo on screen');
assert(!noEntry.includes('รอรายงานตัว'),'No entry is not a waiting check-in');
const checked=state.schoolCell({...registered,athletes:[
 {name:'ด.ช. ทดสอบ',photo:'https://example.org/runner.png'}]});
assert(checked.includes('school-athletes count-1'),'Checked-in athlete is displayed');
assert(checked.includes('https://example.org/runner.png'),'Real checked-in portrait appears');
assert(!checked.includes('school-flag'),'Flag disappears as soon as a check-in appears');
assert(!checked.includes('รอรายงานตัว')&&!checked.includes('ไม่ได้ส่งแข่ง'),
  'With a runner, only student portrait is shown');
const noLogo=state.schoolCell({...registered,logo:null});
assert(noLogo.includes('school-empty-emblem-fallback'),'Missing logo shows a neutral fallback icon');
assert(noLogo.includes('รอรายงานตัว'),'Missing logo still shows the real status text');
assert(css.includes('.school-flag-empty-body'),'Full-width school crest style exists');
assert(css.includes('.school-empty-caption'),'Readable status is below the logo');
// Extra rows used to erase all real Check-in status via fallbackSchools.
const paintStart=js.indexOf('  function paintSchools(',schoolEnd);
const paintEnd=js.indexOf('  function renderRecentResults(',paintStart);
assert(paintStart>=0&&paintEnd>paintStart,'Live school grid renderer must be testable');
const fakeGrid={innerHTML:'',querySelectorAll:()=>[]};
state.el=()=>fakeGrid;
vm.runInContext(js.slice(paintStart,paintEnd),state);
const overflow=Array.from({length:11},(_,i)=>({
 name:'โรงเรียนทดสอบลำดับ'+(i+1),
 registered:i===0,
 heats:i===0?[1]:[],
 athletes:[],
 logo:i===0?registered.logo:null
}));
state.paintSchools(overflow);
assert(fakeGrid.innerHTML.includes('โรงเรียนทดสอบลำดับ1'),
 'First configured school must stay visible when 11 API schools arrive');
assert(fakeGrid.innerHTML.includes('รอรายงานตัว'),
 'Waiting state MUST NOT be overwritten with "ไม่ได้ส่งแข่ง" on overflow');
assert((fakeGrid.innerHTML.match(/class="school-column/g)||[]).length===10,
 'Client should display only ten genuine API schools');
assert(!fakeGrid.innerHTML.includes('โรงเรียนทดสอบลำดับ11'),
 'Only the last overflow school is clipped, not the entire true roster');
console.log('PASS extra schools preserve actual LIVE order and check-in statuses');

console.log('PASS check-in school states: waiting, no entry, confirmed attendance and no-logo fallback');
