/* Shared client-side guidance for the official age-and-school-level race rules.
   Security decisions always re-run in PHP when registering an athlete. */
(function(root) {
  'use strict';
  const levels = ['อนุบาล', 'ประถม', 'มัธยม'];
  function category(ageLimit) {
    const n = Number(ageLimit);
    if (n === 6) return 'อนุบาล';
    if (n >= 8 && n <= 12) return 'ประถม';
    if (n >= 14 && n <= 16) return 'มัธยม';
    return '';
  }
  function check(level, age, ageLimit) {
    level = String(level || '').trim();
    age = Number(age);
    if (!levels.includes(level)) {
      return {allowed:false, reason:'กรุณาระบุระดับที่กำลังศึกษา (อนุบาล/ประถม/มัธยม) ในข้อมูลนักวิ่งก่อนสมัคร'};
    }
    if (!Number.isInteger(age) || age < 1 || age > 100) {
      return {allowed:false, reason:'กรุณาตรวจสอบปีเกิดของนักวิ่ง'};
    }
    if (ageLimit === null || ageLimit === '' || ageLimit === undefined) {
      return {allowed:true, reason:''}; // Open/unclassified events need their separate event rules.
    }
    const max = Number(ageLimit);
    if (!Number.isInteger(max) || max < 1 || max > 99) {
      return {allowed:false, reason:'กำหนดรุ่นอายุของรายการแข่งขันไม่ถูกต้อง'};
    }
    if (age > max) {
      return {allowed:false, reason:'อายุ ' + age + ' ปี เกินรุ่น ' + max + ' ปี'};
    }
    const group = category(max);
    if (group === 'อนุบาล') {
      if (level === 'อนุบาล') return {allowed:true, reason:''};
      return {allowed:false, reason:'รุ่น 6 ปีสงวนสำหรับนักเรียนอนุบาล ไม่เปิดให้ประถมหรือมัธยมสมัคร'};
    }
    if (group === 'ประถม') {
      if (level === 'ประถม' && age >= 8 && age <= 12) return {allowed:true, reason:''};
      if (level === 'มัธยม' && age === 12 && max === 12) return {allowed:true, reason:''};
      return {allowed:false, reason:'รุ่น 8–12 ปีสำหรับประถมอายุ 8–12 ปี (ยกเว้นมัธยมอายุ 12 ปีสมัครรุ่น 12 ปีได้)'};
    }
    if (group === 'มัธยม') {
      // Primary pupils aged 8–12 may move UP to 14/16-year events.
      // Secondary pupils can enter their higher age brackets when under the cap.
      if (level === 'มัธยม') return {allowed:true, reason:''};
      if (level === 'ประถม' && age >= 8 && age <= 12) return {allowed:true, reason:''};
      return {allowed:false, reason:'รุ่น 14–16 ปีสำหรับมัธยม หรือประถมอายุ 8–12 ปีที่ข้ามรุ่นขึ้นมา'};
    }
    return {allowed:false, reason:'รุ่นอายุนี้ไม่อยู่ในกติกาการแข่งขัน (6, 8–12, 14–16 ปี)'};
  }
  const api = Object.freeze({category, check});
  root.Bang1RunnerEligibility = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
