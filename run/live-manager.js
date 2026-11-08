/* Manager/Admin athletics report check-in console.
   Uses existing session/CSRF via bang1Bridge, and a bundled QR decoder so
   no third-party CDN is needed on competition day. */
(function(){
 'use strict';
 const $=id=>document.getElementById(id);
 const escapeHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let last=null,scanning=false,stream=null,decoder=null,frameTimer=0,frameBusy=false;
 let decoderPromise=null,context=null,busy=false,lastScanned='',lastScannedAt=0,poller=0;
 const video=$('bang1ScanVideo'),canvas=document.createElement('canvas');
 const ctx=canvas.getContext('2d',{willReadFrequently:true});
 function isManager(){
   const u=typeof currentUser==='undefined'?null:currentUser;
   return !!u&&['Manager','Admin','TopAdmin'].includes(u.role);
 }
 function isOnPage(){return !!$('page-checkin')?.classList.contains('active')}
 function api(name,data){
   if(!window.bang1Bridge?.adminRequest)return Promise.reject(Error('ยังไม่ได้เชื่อมต่อระบบ'));
   return window.bang1Bridge.adminRequest(name,data);
 }
 function setMessage(message,level='info'){
   const el=$('bang1ScanStatus');if(!el)return;
   el.textContent=message||'';
   el.className='bang1-scan-status is-'+level;
 }
 function displayFixture(f){
   return [f.eventName,f.category,f.level,f.gender,f.heat?'ฮีท '+f.heat:''].filter(Boolean).join(' · ');
 }
 function showData(data){
   last=data;
   const select=$('bang1ActiveFixture');
   const chosen=select.value;
   const fixtures=Array.isArray(data.fixtures)?data.fixtures:[];
   select.replaceChildren();
   const placeholder=new Option('เลือกรายการแข่งขัน/ฮีท','');
   select.appendChild(placeholder);
   fixtures.forEach((f,i)=>{
     const opt=new Option((i+1)+'. '+displayFixture(f)+' ('+f.checked+'/'+f.total+')',f.key);
     select.appendChild(opt);
   });
   if(chosen && fixtures.some(f=>f.key===chosen))select.value=chosen;
   else if(data.active)select.value=data.active.key;
   const f=data.active;
   $('bang1LiveActiveName').textContent=f?displayFixture(f):'ยังไม่ได้เปิดรายการ Active';
   $('bang1LiveActiveCount').textContent=f?'รายงานตัวแล้ว '+f.checked+' / '+f.total+' ใบสมัคร':'กรุณาเลือกรายการแล้วกดเปิดรายการ Active';
   $('bang1ActiveEnd').disabled=!f;
   const teams=Array.isArray(data.teams)?data.teams:[];
   $('bang1CheckinTbody').innerHTML=teams.length?teams.map(x=>
     '<tr><td>'+Number(x.regId)+'</td><td>'+escapeHtml(x.school)+'</td>'+
     '<td>'+escapeHtml(x.runners||'-')+'</td>'+
     '<td>'+escapeHtml(x.lane??'-')+'</td>'+
     '<td><span class="'+(x.checkedAt?'bang1-checked':'bang1-pending')+'">'+
       (x.checkedAt?'✓ '+escapeHtml(x.checkedAt):'รอรายงานตัว')+'</span></td>'+
     '<td>'+(x.checkedAt?'<button type="button" class="bang1-undo-btn" data-undo="'+Number(x.regId)+'">ยกเลิก</button>':'—')+'</td></tr>'
   ).join(''):'<tr><td colspan="6">ยังไม่มีรายการ Active หรือไม่มีใบสมัครในรายการนี้</td></tr>';
   $('bang1OpenLiveLink').href='live/';
 }
 async function refresh(silent=true){
   if(!isManager()||!isOnPage()||busy)return;
   try{
     const data=await api('live_manager_data');
     showData(data);
     if(!silent)setMessage('อัปเดตข้อมูลรายงานตัวแล้ว','success');
   }catch(err){setMessage(err.message||'โหลดรายการไม่สำเร็จ','error')}
 }
 async function mutate(name,data,successText){
   busy=true;
   try{
     const result=await api(name,data);
     const fresh=await api('live_manager_data');
     showData(fresh);
     setMessage(result.message||successText||'บันทึกสำเร็จ',result.isNew===false?'duplicate':'success');
     return result;
   }catch(err){
     setMessage(err.message||'ไม่สามารถบันทึกรายงานตัวได้','error');return null;
   }finally{busy=false}
 }
 async function scanCode(code){
   const raw=String(code||'').trim();
   if(!raw||busy||!isManager())return;
   const now=Date.now();
   if(raw===lastScanned&&now-lastScannedAt<3800)return;
   lastScanned=raw;lastScannedAt=now;
   const result=await mutate('live_scan',{code:raw});
   if(result){
     setMessage((result.isNew?'✓ ':'↺ ')+(result.message||'รายงานตัวแล้ว')+
       ' · '+result.school+' · รหัสใบสมัคร '+result.regId,
       result.isNew?'success':'duplicate');
     if(result.isNew)beep();
     $('bang1ManualCode').value='';
   }else{
     // A mistaken scan must be retryable after Manager changes the Active event.
     lastScanned='';lastScannedAt=0;
   }
 }
 function beep(){
   try{
     const AC=window.AudioContext||window.webkitAudioContext;if(!AC)return;
     if(!context)context=new AC();
     if(context.state==='suspended')context.resume().catch(()=>{});
     const osc=context.createOscillator(),gain=context.createGain();
     osc.type='sine';osc.frequency.value=1050;
     gain.gain.setValueAtTime(.0001,context.currentTime);
     gain.gain.exponentialRampToValueAtTime(.18,context.currentTime+.025);
     gain.gain.exponentialRampToValueAtTime(.0001,context.currentTime+.16);
     osc.connect(gain);gain.connect(context.destination);
     osc.start();osc.stop(context.currentTime+.17);
   }catch(err){}
 }
 function ensureQrDecoder(){
   if(typeof window.jsQR==='function')return Promise.resolve();
   if(decoderPromise)return decoderPromise;
   decoderPromise=new Promise((resolve,reject)=>{
     const tag=document.createElement('script');
     tag.src='vendor/jsQR.js?v=20261109';
     tag.onload=()=>typeof window.jsQR==='function'?resolve():reject(Error('ไม่สามารถเตรียมระบบอ่าน QR'));
     tag.onerror=()=>reject(Error('ไม่สามารถโหลดตัวอ่าน QR Code'));
     document.head.appendChild(tag);
   }).catch(err=>{decoderPromise=null;throw err});
   return decoderPromise;
 }
 async function decodeFrame(){
   if(!scanning||!video||video.readyState<2||frameBusy)return;
   frameBusy=true;
   try{
     let text='';
     if(decoder){
       try{
         const detected=await decoder.detect(video);
         text=detected?.find(v=>v.rawValue)?.rawValue||'';
       }catch(err){decoder=null}
     }
     if(!text&&typeof window.jsQR==='function'&&ctx){
       const width=Math.min(640,video.videoWidth||0);
       if(width){
         const height=Math.max(1,Math.floor(width*(video.videoHeight/video.videoWidth)));
         canvas.width=width;canvas.height=height;
         ctx.drawImage(video,0,0,width,height);
         const pixels=ctx.getImageData(0,0,width,height);
         const qr=window.jsQR(pixels.data,width,height,{inversionAttempts:'attemptBoth'});
         text=qr?.data||'';
       }
     }
     if(text)await scanCode(text);
   }finally{frameBusy=false}
 }
 function frameLoop(time){
   if(!scanning)return;
   if(time-frameTimer>=180){frameTimer=time;decodeFrame().catch(()=>{});}
   requestAnimationFrame(frameLoop);
 }
 function stopCamera(){
   scanning=false;
   if(stream){stream.getTracks().forEach(t=>t.stop());stream=null}
   if(video){video.pause();video.srcObject=null}
   $('bang1CameraStart').disabled=false;
   $('bang1CameraStop').disabled=true;
   $('bang1CameraEmpty').hidden=false;
 }
 async function startCamera(){
   if(!isManager()){setMessage('เฉพาะ Manager หรือ Admin เท่านั้น','error');return}
   if(!navigator.mediaDevices?.getUserMedia){setMessage('อุปกรณ์นี้ไม่รองรับกล้อง โปรดกรอกรหัสใบสมัครแทน','error');return}
   $('bang1CameraStart').disabled=true;setMessage('กำลังเปิดกล้องและตัวอ่าน QR Code...','info');
   try{
     // Prefer rear camera. A local JS QR decoder covers Safari/iOS and Android.
     await ensureQrDecoder();
     try{
       if('BarcodeDetector' in window)decoder=new BarcodeDetector({formats:['qr_code']});
     }catch(err){decoder=null}
     try{
       stream=await navigator.mediaDevices.getUserMedia({audio:false,video:{
         facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}}});
     }catch(err){
       stream=await navigator.mediaDevices.getUserMedia({audio:false,video:true});
     }
     video.srcObject=stream;
     await video.play();
     scanning=true;
     $('bang1CameraEmpty').hidden=true;
     $('bang1CameraStop').disabled=false;
     setMessage('กล้องพร้อมใช้งาน · เล็ง QR Code บนใบสมัครเข้ากรอบสี่เหลี่ยม','success');
     requestAnimationFrame(frameLoop);
   }catch(err){
     stopCamera();
     setMessage('เปิดกล้องไม่ได้: '+(err.message||'กรุณาอนุญาตใช้กล้อง')+' · ใช้ช่องกรอกรหัสใบสมัครแทน','error');
   }
 }
 function setup(){
   const page=$('page-checkin');if(!page)return;
   $('bang1CameraStart').addEventListener('click',startCamera);
   $('bang1CameraStop').addEventListener('click',stopCamera);
   $('bang1SetActive').addEventListener('click',async()=>{
     const key=$('bang1ActiveFixture').value;
     if(!key){setMessage('กรุณาเลือกรายการแข่งขันก่อน','error');return}
     await mutate('live_select',{fixtureKey:key});
   });
   $('bang1ActiveEnd').addEventListener('click',async()=>{
     if(!confirm('ยืนยันปิดการแสดงรายการ Active บนจอ Live?'))return;
     await mutate('live_select',{fixtureKey:''});
   });
   $('bang1CheckinRefresh').addEventListener('click',()=>refresh(false));
   $('bang1ManualForm').addEventListener('submit',e=>{
     e.preventDefault();scanCode($('bang1ManualCode').value);
   });
   $('bang1CheckinTbody').addEventListener('click',async e=>{
     const btn=e.target.closest('button[data-undo]');
     if(!btn)return;
     const regId=Number(btn.dataset.undo);
     if(!confirm('ยืนยันยกเลิกการรายงานตัว ใบสมัคร #'+regId+' ?'))return;
     btn.disabled=true;
     await mutate('live_undo',{regId});
   });
   const menu=document.querySelector('.menu button[data-page="checkin"]');
   if(menu)menu.addEventListener('click',()=>setTimeout(()=>refresh(true),0));
   document.addEventListener('visibilitychange',()=>{
     if(document.visibilityState==='hidden')stopCamera();
     else if(isOnPage())refresh(true);
   });
   // Stop the camera when leaving this tab/page, releasing the device to other apps.
   const observer=new MutationObserver(()=>{if(!isOnPage())stopCamera();else if(!last)refresh(true)});
   observer.observe(page,{attributes:true,attributeFilter:['class']});
   window.addEventListener('pagehide',stopCamera);
   poller=setInterval(()=>{if(isOnPage())refresh(true);else if(scanning)stopCamera()},3500);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup);
 else setup();
})();
