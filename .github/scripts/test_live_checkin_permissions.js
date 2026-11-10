'use strict';
// Regression: LIVE check-in is an explicit Admin grant for each Manager.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=path=>fs.readFileSync(path,'utf8');
const bootstrap=read('run/inc/bootstrap.php');
const api=read('run/api.php');
const live=read('run/live-api.php');
const admin=read('run/admin-config.php');
const schema=read('run/setup/migrate.php');
const panel=read('run/admin-tabs.js');
const controller=read('run/live-manager.js');
const html=read('run/legacy-app.html');
const guidance=read('run/role-guidance.js');
assert(schema.includes("ADD COLUMN can_live_checkin TINYINT(1) NOT NULL DEFAULT 0"),
 'All existing and future Manager accounts must be denied by default');
assert(bootstrap.includes("can_live_checkin FROM coaches WHERE id=?"),
 'Every authenticated API request must refresh permission from MariaDB');
assert(bootstrap.includes("function require_live_checkin_access(array $u)"),
 'The backend must enforce Manager grants');
assert(bootstrap.includes("($u['canLiveCheckin']??false)!==true"),
 'Manager grant must be explicitly TRUE, never granted by role alone');
assert(api.includes("['live_manager_data','live_select','live_runner_set','live_display_set']"),
 'All four privileged actions must go through the LIVE module');
assert(live.includes("require_live_checkin_access($u);"),
 'LIVE data and updates must be behind the new server guard');
assert(admin.includes("if ($action === 'admin_coach_live_checkin_access')"),
 'Admin needs grant and revoke route');
assert(admin.includes("must_post();")&&admin.includes("UPDATE coaches SET can_live_checkin=? WHERE id=?"),
 'Grant/revoke must persist with authenticated POST');
assert(admin.includes("if(!$account||$account['role']!=='Manager')"),
 'Only Manager accounts can be granted the LIVE check-in ability');
assert(admin.includes("UPDATE coaches SET can_live_checkin=0 WHERE id=?"),
 'Changing away from Manager must clear an old grant');
assert(panel.includes("api('admin_coach_live_checkin_access',{id:Number(manager.id),allowed:allow})"),
 'Admin UI must call grant/revoke for a specific Manager');
assert(controller.includes("user.role==='Manager'&&user.canLiveCheckin===true"),
 'Browser LIVE manager panel must not auto-enable every Manager');
for(const script of ['role-guidance.js','admin-tabs.js','live-manager.js']){
 assert(html.includes(script+'?v=20261110-manager-checkin24'),
   'New version of '+script+' must bypass old browser cache');
}
const state={window:{},document:{readyState:'loading',addEventListener(){}}};
vm.createContext(state);
vm.runInContext(guidance,state);
const {can}=state.window.bang1Role;
const denied={role:'Manager',canLiveCheckin:false};
const granted={role:'Manager',canLiveCheckin:true};
assert.equal(can('checkin',denied),false,'Unapproved Manager may not navigate to check-in');
assert.equal(can('admin',denied),true,'Unapproved Manager still reviews runner approvals');
assert.equal(can('checkin',granted),true,'Approved Manager may navigate to check-in');
assert.equal(can('checkin',{role:'Manager'}),false,'Missing permission must deny by default');
assert.equal(can('checkin',{role:'Admin'}),true,'Admin always has check-in access');
assert.equal(can('checkin',{role:'Teacher'}),false,'Teachers cannot gain Manager privileges');
assert.equal(can('checkin',{role:'Staff'}),false,'Staff cannot gain Manager privileges');
console.log('PASS Manager LIVE check-in: Admin grants, default-deny, server enforcement and menu restrictions');
