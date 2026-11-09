'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
const load=p=>fs.readFileSync(path.join(root,p),'utf8');
const js=load('run/live/live.js'),html=load('run/live/index.php'),
      css=load('run/school-branding.css'),manager=load('run/admin-tabs.js'),
      api=load('run/live-api.php');
assert(js.includes('function schoolFlag('),'Flag renderer must exist');
assert(js.includes("schoolFlag(school.logo,'heading')"),'Check-in school headings use logos');
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
assert(css.includes('@keyframes school-flag-wave'),'Flag wave animation exists');
assert(css.includes('.race-breaking .school-flag-medal'),'Medal cards use well-positioned flags');
console.log('PASS school logo uploads UI / animated flags in Live, Sport and winners');
