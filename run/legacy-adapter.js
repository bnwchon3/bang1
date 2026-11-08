/* Compatibility adapter: original Apps Script interface -> PHP/MariaDB.
   Only transports data; it does not alter the original interface or layout. */
(() => {
 'use strict';
 let csrf='';
 let sessionUser=null;
 const errorText = e => e && e.message ? e.message : String(e||'Server error');
 async function request(action,data) {
   const options={credentials:'same-origin',method:data===undefined?'GET':'POST',headers:{}};
   if(data!==undefined){
     if(csrf)options.headers['X-CSRF-Token']=csrf;
     if(data instanceof FormData)options.body=data;
     else {options.headers['Content-Type']='application/json';options.body=JSON.stringify(data);}
   }
   const parts=String(action).split('&');
   const endpoint='api.php?action='+encodeURIComponent(parts.shift())+(parts.length?'&'+parts.join('&'):'');
   // Authentication must never remain blocked forever by a stalled network call.
   if(action==='login' || action==='me') {
     const controller=new AbortController();
     options.signal=controller.signal;
     // Keep the limit specific to short authentication requests; uploads and
     // backups remain unrestricted.
     try {
       const response=await Promise.race([
         fetch(endpoint,options),
         new Promise((_,reject)=>{
           options._authTimeout=setTimeout(()=>{
             controller.abort();
             reject(new Error('การเชื่อมต่อใช้เวลานาน กรุณาลองเข้าสู่ระบบอีกครั้ง'));
           },20000);
         })
       ]);
       clearTimeout(options._authTimeout);
       let result;
       try{result=await response.json()}catch{throw new Error('Server HTTP '+response.status);}
       if(response.status>=400||result.success===false)throw new Error(result.message||'ทำรายการไม่สำเร็จ');
       return result;
     } finally {
       clearTimeout(options._authTimeout);
     }
   }
   const response=await fetch(endpoint,options);
   let result;
   try{result=await response.json()}catch{throw new Error('Server HTTP '+response.status);}
   if(response.status>=400||result.success===false)throw new Error(result.message||'ทำรายการไม่สำเร็จ');
   return result;
 }
 const toList=(r)=>Array.isArray(r.data)?r.data:[];
 const toEvent=(r)=>r;
 const toRunners=(list)=>list.map(r=>({
   ...r,PhotoUrl:photoLink(r.Photo), Photo:r.Photo||'',CheckedBy:r.CheckedBy||'',FailReason:r.FailReason||''
 }));
 function photoLink(value) {
   const raw=String(value||'').trim();if(!raw)return '';
   let u;try{u=new URL(raw)}catch{return ''}
   if(u.protocol!=='https:')return '';
   if(u.hostname==='drive.google.com'){
     const id=u.searchParams.get('id')||(u.pathname.match(/\/(?:file\/d|d)\/([A-Za-z0-9_-]{15,})(?:\/|$)/)||[])[1];
     if(id&&/^[A-Za-z0-9_-]{15,}$/.test(id))return 'https://drive.google.com/thumbnail?id='+encodeURIComponent(id)+'&sz=w800';
   }
   return u.href;
 }
 function allEvents(){
   return request('events').then(r=>toList(r));
 }
 function availableEvents(user,school){
   return Promise.all([request('events'),request('registrations')]).then(([ev,regs])=>{
     const fullSchool=(user?.role==='Admin'||user?.role==='TopAdmin')?(school||user?.schoolName):user?.schoolName;
     const key=r=>[r.EventName||'',r.Level||'',r.Category||''].join('|');
     const taken=new Set(toList(regs).filter(r=>r.SchoolName===fullSchool).map(key));
     // An imported program may contain several preliminary heat slots plus a later
     // final for the same race. Teachers register once per competition, not per slot.
     const chosen=new Map();
     const finalSlot=r=>r.Remark==='ชิงชนะเลิศ'||r.HeatPlan==='ชิง';
     for(const e of toList(ev)){
       const k=key(e),prior=chosen.get(k);
       if(!prior||(finalSlot(prior)&&!finalSlot(e)))chosen.set(k,e);
     }
     return [...chosen.values()].filter(r=>!taken.has(key(r)))
      .sort((a,b)=>String(a.EventName).localeCompare(String(b.EventName),'th',{numeric:true,sensitivity:'base'}))
      .map(r=>({...r,Label:String(r.EventName||'')+(r.Level?' | '+r.Level:'')+(r.Gender?' | '+r.Gender:'')}));
   });
 }
 const config=()=>(!sessionUser?new Promise(resolve=>window.addEventListener('bang1:login',()=>config().then(resolve),{once:true})):request('features').then(r=>{
   const names={'รับลงทะเบียนนักวิ่ง':'runnerReg','รับสมัครรายการวิ่ง':'eventReg','แก้ไขรูปภาพ':'photoEdit','แก้ไขหลักฐาน':'proofEdit'};
   const features={};
   for(const f of toList(r)) {
     const key=names[f.name];if(!key)continue;
     features[key]={name:f.name,isOpen:!!f.isOpen,statusText:f.status||'',
       openAtEpochMs:f.opens_at?new Date(f.opens_at.replace(' ','T')+'+07:00').getTime():null,
       closeAtEpochMs:f.closes_at?new Date(f.closes_at.replace(' ','T')+'+07:00').getTime():null};
   }
   const print=toList(r).find(x=>x.name==='ปุ่มพิมพ์');
   return {serverTimeEpochMs:r.serverTimeEpochMs||Date.now(),features,_printStatus:print?print.status:null};
 }));
 const methods={
   loginCoach:async(email,password)=>{
     const result=await request('login',{email,password});
     csrf=result.csrf||'';sessionUser=result.user;
     // Role-specific bootstrap: Manager/Staff must never request the Teacher/Admin
     // events API. A secondary event-list failure must not turn a valid login into
     // a permanently blocking loading overlay.
     if(['Teacher','Admin','TopAdmin'].includes(result.user?.role)) {
       try {
         const events=await allEvents();
         if(typeof eventsData!=='undefined')eventsData=events;
         if(typeof buildEventDropdown==='function')buildEventDropdown();
       } catch(error) {
         console.warn('Bang1: optional event preload unavailable',errorText(error));
       }
     }
     window.dispatchEvent(new CustomEvent('bang1:login'));
     return {...result,message:'เข้าสู่ระบบสำเร็จ'};
   },
   getEvents:()=>sessionUser?allEvents():Promise.resolve([]),
   getActiveSchoolNames:()=>request('schools').then(r=>toList(r).filter(x=>x.active==null||x.active==='ใช้งาน'||x.active==='Active').map(x=>x.name)),
   getRunnersForSchool:(school,role)=>request('runners').then(r=>toRunners(toList(r)).filter(x=>!school||!(['Admin','TopAdmin'].includes(sessionUser?.role))||x.SchoolName===school)),
   getRunnersForRegistration:()=>request('runners').then(r=>toRunners(toList(r))),
   saveRunner:(form)=>request('save_runner',form).then(r=>({...r,message:'บันทึกข้อมูลนักวิ่งเรียบร้อยแล้ว'})),
   deleteRunner:(id)=>request('delete_runner',{id}).then(r=>({...r,message:'ลบนักวิ่งเรียบร้อยแล้ว'})),
   uploadRunnerFile:async(payload)=>{
     const bytes=Uint8Array.from(atob(payload.base64Data),c=>c.charCodeAt(0));
     const data=new FormData();
     data.append('runnerId',payload.runnerId);
     data.append('type',payload.type);
     data.append('file',new Blob([bytes],{type:payload.mimeType}),payload.fileName||'upload');
     return request('upload',data).then(r=>({...r,message:'อัปโหลดไฟล์สำเร็จ'}));
   },
   updateRunnerStatus:(id,status,reason)=>request('update_status',{id,status,reason:typeof reason==='string'?reason:''}).then(r=>({...r,message:'อัปเดตสถานะเรียบร้อยแล้ว'})),
   getRegistrationsForSchool:()=>request('registrations').then(r=>toList(r)),
   registerEvent:(form)=>request('save_registration',{regId:form.regId,eventId:form.eventId,runnerIds:form.runnerIds,schoolName:form.schoolName}).then(r=>({...r,message:form.regId?'บันทึกการแก้ไขรายการวิ่งสำเร็จ':'สมัครรายการวิ่งสำเร็จ',regId:r.id})),
   deleteRegistration:(id)=>request('delete_registration',{id}).then(r=>({...r,message:'ลบรายการสมัครเรียบร้อยแล้ว'})),
   getAvailableEventsForDropdown:(user,school)=>availableEvents(user,school),
   getEventsForSchedule:()=>sessionUser?request('schedule').then(r=>toList(r)):Promise.resolve([]),
   getEventRunnersForSchedule:(eventName,heat)=>request('schedule').then(r=>
     toList(r).filter(e=>e.EventName===eventName&&String(e.Heat||'')===String(heat||''))
      .flatMap(e=>e.entries||[]).map(x=>({SchoolName:x.SchoolName,RunnerName:x.RunnerName,Lane:x.Lane}))
      .sort((a,b)=>Number(a.Lane)-Number(b.Lane))),
   autoAssignHeatsAndLanes:()=>request('auto_heats',{}).then(r=>({...r,message:'จัดฮีทและลู่เรียบร้อย'})),
   getAdminConfig:config,
   getUploadSystemStatus:()=>config().then(r=>({photo:r.features.photoEdit?.isOpen!==false,idproof:r.features.proofEdit?.isOpen!==false})),
   getPrintButtonsEnabled:()=>config().then(r=>{const f=r._printStatus;return f===null||f==='เปิดระบบ';}),
   getRaceResults:()=>sessionUser?request('original_results').then(r=>toList(r)):Promise.resolve([]),
 };
 const build=(onSuccess,onFailure)=>new Proxy({},{
   get(_target,key){
     if(key==='withSuccessHandler')return fn=>build(fn,onFailure);
     if(key==='withFailureHandler')return fn=>build(onSuccess,fn);
     if(typeof key!=='string')return undefined;
     return (...args)=>{
       const method=methods[key];
       if(!method){const e=new Error('ไม่ได้เชื่อมต่อคำสั่ง '+key);(onFailure||console.error)(e);return;}
       Promise.resolve().then(()=>method(...args)).then(res=>{if(onSuccess)onSuccess(res)}).catch(e=>{
         if(onFailure)onFailure(e);else console.error('Bang1',key,errorText(e));
       });
     };
   }
 });
 window.google={...(window.google||{}),script:{...(window.google?.script||{}),run:build(null,null)}};
 window.bang1Bridge={
   async restore(){
     try{
       const r=await request('me');
       sessionUser=r.user||null;csrf=r.csrf||'';
       if(sessionUser)window.dispatchEvent(new CustomEvent('bang1:login'));
       return sessionUser;
     }catch{return null;}
   },
   async logout(){
     const response=await request('logout',{});
     sessionUser=null;csrf='';
     return response;
   },
   photoLink,
   adminRequest: (action,data)=>request(action,data),
   adminDownload: async (data)=>{
     const response=await fetch('api.php?action=admin_backup_export',{
       method:'POST',credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
       body:JSON.stringify(data)
     });
     if(!response.ok){
       let message='ไม่สามารถดาวน์โหลดไฟล์ Backup ได้';
       try{const error=await response.json();message=error.message||message;}catch{}
       throw new Error(message);
     }
     return response.blob();
   },
 };
})();