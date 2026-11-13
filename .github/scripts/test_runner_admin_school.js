/* Regression guard for admin school-wide runner management. */
'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const ui=fs.readFileSync('run/legacy-app.html','utf8');
const api=fs.readFileSync('run/api.php','utf8');
const adapter=fs.readFileSync('run/legacy-adapter.js','utf8');

assert.match(ui,/id="runnerAdminSchoolFilter"/,'School selector must be present');
assert.match(ui,/function isRunnerGlobalAdmin\(\)/,'Admin/TopAdmin check must exist');
assert.match(ui,/\['Admin','TopAdmin'\]\.includes\(currentUser\.role\)/,'Global roster must be Admin-only');
assert.match(ui,/getRunnersForSchool\(isRunnerGlobalAdmin\(\) \? '' : currentUser\.schoolName/,'Admin should load all schools and teachers should load their own');
assert.match(ui,/function getVisibleRunnerRoster\(\)/,'Runner filter must exist');
assert.match(ui,/all\.filter\(r => String\(r\.SchoolName \|\| ''\) === school\)/,'School filter must use stored school names');
assert.match(ui,/data-athlete-admin-edit/,'Quick edit must exist');
assert.match(ui,/data-athlete-admin-delete/,'Quick delete must exist');
assert.match(ui,/if \(admin\) \{\s*const edit = tr\.querySelector/,'Quick actions must be wired only for Admin');
assert.match(ui,/SchoolName: get\('runnerSchool'\)\.trim\(\)/,'Admin school must be submitted on create');
assert.match(ui,/if \(isRunnerGlobalAdmin\(\) && !form\.RunnerID && !form\.SchoolName\)/,'New admin runners must have a chosen school');

assert.match(adapter,/getSchoolNamesForRunnerAdmin:/,'School directory must be loadable');
assert.match(adapter,/getRunnersForSchool:\(school,role\)=>request\('runners'\)/,'Roster bridge must not silently limit Admin to their profile school');
assert.match(adapter,/x\.SchoolName===school/,'School filter must remain available');

assert.match(api,/function school_access\(array \$u,string \$school\):void/,'Server school permission check must remain');
assert.match(api,/if\(!is_admin\(\$u\)&&\$school!==\(\$u\['schoolName'\]\?\?''\)\)/,'School isolation for nonadmins must remain');
assert.match(api,/if\(is_admin\(\$u\)&&\$id===0\)/,'Admin creation requires an explicit school');
assert.match(api,/SELECT name FROM schools WHERE name=\? LIMIT 1/,'Chosen school must exist');
assert.match(api,/bang1_runner_delete_block_reason/,'Race result and check-in safeguards must remain');

console.log('PASS Admin roster scope, school selection, creation, actions and deletion safeguards');
