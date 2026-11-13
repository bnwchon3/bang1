/* Keep the browser candidate filtering aligned with server education/age rules. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {check,category}=require('../../run/runner-eligibility.js');
const html=fs.readFileSync('run/legacy-app.html','utf8');
const api=fs.readFileSync('run/api.php','utf8');
const rules=fs.readFileSync('run/runner-rules.php','utf8');
const cases=[
 ['อนุบาล',6,6,true],['อนุบาล',6,8,false],
 ['ประถม',6,6,false],['ประถม',8,8,true],
 ['ประถม',10,8,false],['ประถม',10,12,true],
 ['ประถม',12,12,true],['ประถม',12,14,true],
 ['ประถม',12,16,true],['ประถม',13,16,false],
 ['มัธยม',11,12,false],['มัธยม',12,12,true],
 ['มัธยม',12,14,true],['มัธยม',12,16,true],
 ['มัธยม',14,12,false],['มัธยม',14,14,true],
 ['มัธยม',15,14,false],['มัธยม',15,16,true],
 ['มัธยม',16,16,true],['มัธยม',17,16,false],
 ['',12,12,false],['อนุบาล',5,6,true]
];
for(const [schoolLevel,age,eventAge,expected] of cases){
 assert.equal(check(schoolLevel,age,eventAge).allowed,expected,
   'Mismatch for '+JSON.stringify({schoolLevel,age,eventAge}));
}
assert.equal(category(6),'อนุบาล');
assert.equal(category(12),'ประถม');
assert.equal(category(16),'มัธยม');
assert.match(html,/<select id="runnerLevel" required>/,
  'School level must be selected explicitly, not inferred solely from age');
assert.match(html,/Bang1RunnerEligibility\.check\(r\.Level,ageNum,ageLimit\)/,
  'Datalist must filter by actual level and competition age');
assert.match(html,/eligibilityApi\.check\(athlete\.Level,Number\(getRunnerAge\(athlete\)\),filters\.ageLimit\)/,
  'Client submission must recheck all competitors, including substitutes');
assert.match(api,/bang1_runner_eligibility_reason\(\(string\)\(\$r\['level'\]/,
  'Server must reject ineligible athletes even on forged requests');
assert.match(rules,/\$schoolLevel==='มัธยม'&&\$age===12&&\$eventAgeLimit===12/,
  'Secondary-year-12 exception must be exact, not under-12');
console.log('PASS browser/server school-level and age requirements ('+cases.length+' cases)');
