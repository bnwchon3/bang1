/* Regression for Olympic podium order and portrait-safe relay cards.
   Only extracts pure card render functions; never accesses live student data. */
'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const js=fs.readFileSync(path.join(root,'run/live/live.js'),'utf8');
const css=fs.readFileSync(path.join(root,'run/live/live.css'),'utf8');
const html=fs.readFileSync(path.join(root,'run/live/index.php'),'utf8');
const start=js.indexOf('  function breakingPortrait(person){');
const end=js.indexOf('  function breakingDecorations(finalRound){',start);
assert(start>=0&&end>start,'Pure winner card functions must be found');
const context={
 esc:value=>String(value??'').replace(/[&<>"']/g,
   char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]))
};
vm.createContext(context);
vm.runInContext(js.slice(start,end),context);
const athlete=(n)=>({name:'นักกรีฑา '+n,photo:'https://example.org/portrait'+n+'.jpg'});
function card(rank,relay,final=true,people=[athlete(1)]){
 return context.breakingResultCard({place:rank,school:'โรงเรียนตัวอย่าง',athletes:people},relay,final);
}
function occurrences(s,term){return s.split(term).length-1}
const gold=card(1,false);
const silver=card(2,false);
const bronze=card(3,false);
for(const [markup,rank] of [[gold,1],[silver,2],[bronze,3]]){
 assert(markup.includes('race-podium-slot race-podium-rank-'+rank),
   'Olympic base must be tied to actual official place '+rank);
 assert(markup.includes('race-podium-number">'+rank+'</span>'),
   'Podium base number must match official place '+rank);
 assert(markup.includes('fifa-photo-foreground'),'Original portrait must be foreground');
}
assert(!card(1,false,false).includes('race-podium-slot'),
 'Qualifiers must have player cards WITHOUT medal podium blocks');
assert(gold.includes('GOLD MEDAL')&&silver.includes('SILVER MEDAL')&&bronze.includes('BRONZE MEDAL'));
const relay=card(1,true,true,[athlete(1),athlete(2),athlete(3),athlete(4),athlete(5)]);
assert(relay.includes('is-relay-team'),'Relay uses dedicated designed player-card layout');
assert(relay.includes('fifa-player-portraits is-relay'),'Relay uses portrait-safe grid');
assert.equal(occurrences(relay,'class="fifa-player-photo"'),4,'One full portrait frame for every relay runner');
assert.equal(occurrences(relay,'class="fifa-photo-foreground"'),4,
 'All 4 real vertical images must display instead of a cropped composite');
assert(!relay.includes('portrait5.jpg'),'A fifth substitute must not crowd the 4-runner card');
assert(relay.includes('นักกรีฑาที่ลงทะเบียน 4 / 4 คน'),'Never claim individual medals based on school rankings');
assert(card(2,true,false,[]).includes('ยังไม่มีรูป'),'Missing actual athlete pictures use placeholders');
const podiumCSS=css.slice(css.indexOf('OLYMPIC VICTORY PODIUM'));
assert(podiumCSS.length>8000,'Expected full Olympic and relay CSS');
assert(/\.race-podium-slot\.race-podium-rank-1\s*\{\s*grid-column:2\b/.test(podiumCSS),
 'Gold MUST be center column');
assert(/\.race-podium-slot\.race-podium-rank-2\s*\{\s*grid-column:1\b/.test(podiumCSS),
 'Silver MUST be on left');
assert(/\.race-podium-slot\.race-podium-rank-3\s*\{\s*grid-column:3\b/.test(podiumCSS),
 'Bronze MUST be on right');
assert(podiumCSS.includes('object-fit:contain!important'),
 'Authentic vertical pictures must be fully visible; never cropped');
assert(/\.fifa-race-card \.fifa-player-portraits\.is-relay\s*\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/s.test(podiumCSS),
 'Relay grid should offer four full-height portrait spaces');
assert(js.includes('[2,1,3].flatMap'),'Render order must place GOLD in the middle');
assert(js.includes('BREAKING_DURATION_MS=15000'),'Keep scoreboard 15 seconds');
assert(html.includes('crest-order21'),'Live entry must force refresh of new JS/CSS');
console.log('PASS Olympic 2-1-3 podium, photo-safe relay 2x2, real image/placeholder and 15s duration');
