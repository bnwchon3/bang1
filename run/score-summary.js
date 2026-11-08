/* Workbook-style scoreboard. Uses the existing Bang1 theme, no new admin controls. */
(function(){
'use strict';
const $=s=>document.querySelector(s);
const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&quot;'}[ch]));
let inFlight=false;
function permitted(){
  // School totals are public; editing results remains Staff-only elsewhere.
  return true;
}
function message(text,error=false){
  const node=$('#bang1ScoreSummaryMessage');
  if(node){node.textContent=text;node.style.color=error?'#fca5a5':'#94a3b8';}
}
function init(){
  const table=$('#bang1ScoreSummaryTable');
  const button=$('#bang1ScoreSummaryRefresh');
  if(!table||!button)return;
  button.addEventListener('click',load);
}
async function load(){
  if(!permitted()||inFlight)return;
  const table=$('#bang1ScoreSummaryTable');
  const refresh=$('#bang1ScoreSummaryRefresh');
  if(!table||!refresh)return;
  inFlight=true;
  refresh.disabled=true;
  message('กำลังคำนวณคะแนนจากผลการแข่งขันรอบชิงชนะเลิศ...');
  try{
    const response=await window.bang1Bridge.adminRequest('score_summary');
    if(!response||response.success!==true)throw new Error(response?.message||'ไม่สามารถโหลดคะแนน');
    const ages=Array.isArray(response.ages)?response.ages:[6,8,10,12,14,16];
    const schools=Array.isArray(response.schools)?response.schools:[];
    const header='<thead><tr><th scope="col" class="bang1-summary-corner">รุ่นอายุ</th>'+
      schools.map(s=>'<th scope="col">'+esc(s.schoolName)+'</th>').join('')+'</tr></thead>';
    const rows=ages.map(age=>{
      const values=schools.map(s=>Number(s.byAge?.[String(age)]??0));
      const highest=Math.max(0,...values);
      return '<tr><th scope="row">รุ่นอายุ '+esc(age)+' ปี</th>'+
        values.map(score=>{
          // Highlight every shared maximum; avoid marking all-zero age groups.
          const best=highest>0&&score===highest;
          return '<td'+(best?' class="bang1-summary-best" title="คะแนนสูงสุดของรุ่นอายุ '+esc(age)+' ปี"':'')+'>'+
            score+'</td>';
        }).join('')+'</tr>';
    }).join('');
    const total='<tr class="bang1-summary-total"><th scope="row">คะแนนรวม</th>'+
      schools.map(s=>'<td>'+Number(s.total||0)+'</td>').join('')+'</tr>';
    const rank='<tr class="bang1-summary-rank"><th scope="row">อันดับ</th>'+
      schools.map(s=>'<td>'+(s.rank==null?'–':Number(s.rank))+'</td>').join('')+'</tr>';
    table.innerHTML=header+'<tbody>'+rows+total+rank+'</tbody>';
    const completed=Number(response.scoredFixtures||0);
    const all=Number(response.finalFixtures||0);
    message('คำนวณจากรอบชิงชนะเลิศที่มีการบันทึกผลแล้ว '+completed+
      ' รายการ'+(all>0?' จากรายการรอบชิง '+all+' รายการ':'')+
      ' • วิ่งเดี่ยว อันดับ 1–3 = 5, 3, 2 คะแนน • วิ่งผลัด = 7, 5, 3 คะแนน');
    if(Array.isArray(response.unclassifiedEvents)&&response.unclassifiedEvents.length){
      const warning=$('#bang1ScoreSummaryWarning');
      if(warning){
        warning.hidden=false;
        warning.textContent='ยังไม่รวมคะแนนรายการที่ตรวจไม่พบรุ่นอายุหรือประเภทวิ่ง: '+
          response.unclassifiedEvents.join(', ');
      }
    }else{
      const warning=$('#bang1ScoreSummaryWarning');
      if(warning){warning.hidden=true;warning.textContent='';}
    }
  }catch(error){
    message('โหลดสรุปผลการแข่งขันไม่สำเร็จ: '+(error?.message||'กรุณาลองใหม่อีกครั้ง'),true);
  }finally{
    inFlight=false;
    refresh.disabled=false;
  }
}
window.loadBang1ScoreSummary=load;
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
