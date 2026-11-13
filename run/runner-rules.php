<?php
declare(strict_types=1);

/**
 * A Thai citizen ID has 13 digits. An alternate school identifier for a
 * non-Thai pupil begins with G and contains exactly 12 following digits.
 * Keep IDs as strings: a leading zero is significant.
 */
function bang1_normalize_citizen_id(string $value): string {
    return strtoupper(trim($value));
}

function bang1_valid_citizen_id(string $value): bool {
    return preg_match('/\\A(?:[0-9]{13}|G[0-9]{12})\\z/D', $value) === 1;
}

/**
 * Athletics age categories are expressed in event titles, e.g. "(6 ปี ชาย)".
 * The bracket is an AGE limit, while actual school enrollment level is a
 * separate compulsory eligibility constraint (checked below).
 * Calculate age from Buddhist birth year against the competition calendar year.
 */
function bang1_event_age_limit(string $eventName): ?int {
    if(preg_match('/[\\(（]\\s*([0-9]{1,2})\\s*ปี(?:\\s|[\\)）])/u', $eventName, $m)!==1)
        return null;
    $age=(int)$m[1];
    return $age>0 && $age<100 ? $age : null;
}
function bang1_runner_age_at_year(int $birthYear, int $competitionYearBE): ?int {
    $age=$competitionYearBE-$birthYear;
    return $birthYear>0 && $age>=1 && $age<=100 ? $age : null;
}

/**
 * Registration eligibility is determined by current SCHOOL level plus the
 * maximum-age competition bracket, not by the fixture's old education tag.
 *
 * - Age 6: kindergarten only, only the 6-year bracket.
 * - Age 8–12 brackets: primary school pupils age 8–12, plus the express
 *   exception allowing a 12-year-old secondary pupil in the 12-year bracket.
 * - Age 14–16 brackets: secondary pupils, or primary pupils age 8–12
 *   moving UP in age group. No kindergarten promotion.
 * A null age bracket means an open/other event that needs separate rules.
 * Returns null if eligible; otherwise a human-readable refusal reason.
 */
function bang1_runner_eligibility_reason(
    string $schoolLevel, ?int $age, ?int $eventAgeLimit
): ?string {
    $schoolLevel=trim($schoolLevel);
    if(!in_array($schoolLevel,['อนุบาล','ประถม','มัธยม'],true))
        return 'กรุณาระบุระดับที่กำลังศึกษา (อนุบาล/ประถม/มัธยม) ในข้อมูลนักวิ่งก่อนสมัคร';
    if($age===null||$age<1||$age>100)
        return 'กรุณาตรวจสอบปีเกิดของนักวิ่ง';
    if($eventAgeLimit===null)return null;
    if($eventAgeLimit<1||$eventAgeLimit>99)
        return 'กำหนดรุ่นอายุของรายการแข่งขันไม่ถูกต้อง';
    if($age>$eventAgeLimit)
        return 'อายุ '.$age.' ปี เกินรุ่น '.$eventAgeLimit.' ปี';

    if($eventAgeLimit===6)
        return $schoolLevel==='อนุบาล'
            ? null : 'รุ่น 6 ปีสงวนสำหรับนักเรียนอนุบาล ไม่เปิดให้ประถมหรือมัธยมสมัคร';

    if($eventAgeLimit>=8&&$eventAgeLimit<=12){
        if($schoolLevel==='ประถม'&&$age>=8&&$age<=12)return null;
        if($schoolLevel==='มัธยม'&&$age===12&&$eventAgeLimit===12)return null;
        return 'รุ่น 8–12 ปีสำหรับประถมอายุ 8–12 ปี (ยกเว้นมัธยมอายุ 12 ปีสมัครรุ่น 12 ปีได้)';
    }

    if($eventAgeLimit>=14&&$eventAgeLimit<=16){
        if($schoolLevel==='มัธยม')return null;
        if($schoolLevel==='ประถม'&&$age>=8&&$age<=12)return null;
        return 'รุ่น 14–16 ปีสำหรับมัธยม หรือประถมอายุ 8–12 ปีที่ข้ามรุ่นขึ้นมา';
    }
    return 'รุ่นอายุนี้ไม่อยู่ในกติกาการแข่งขัน (6, 8–12, 14–16 ปี)';
}

/** Counts every registered athlete, including relays, without trusting a form. */
function bang1_runner_registration_member_count(string $csv): int {
    $ids = array_filter(array_map('trim', explode(',', $csv)),
        static fn(string $id): bool => $id !== '');
    return count(array_unique($ids));
}

/**
 * A runner may be removed immediately once there is no current official
 * result or check-in. A multi-athlete registration must be edited separately
 * so deleting one student never silently cancels their teammates' entry.
 * Historical results reside in different tables and are never changed here.
 */
function bang1_runner_delete_block_reason(
    bool $hasCurrentResult, bool $hasCheckin, int $registeredAthletes
): ?string {
    if ($hasCurrentResult) {
        return 'ไม่สามารถลบนักวิ่งได้ เนื่องจากรายการแข่งขันปัจจุบันมีการบันทึกผลแล้ว';
    }
    if ($hasCheckin) {
        return 'ไม่สามารถลบนักวิ่งได้ เนื่องจากมีการเช็กชื่อรายงานตัวในรายการแข่งขันปัจจุบันแล้ว';
    }
    if ($registeredAthletes > 1) {
        return 'นักวิ่งมีชื่ออยู่ในใบสมัครที่มีเพื่อนร่วมทีม กรุณาแก้ไขหรือลบใบสมัครรายการนั้นก่อน เพื่อไม่ให้กระทบนักวิ่งคนอื่น';
    }
    return null;
}

/** Any correction invalidates prior review status.
 * Only a photo-only replacement with a trusted approved image may use face review.
 */
function bang1_runner_status_after_edit(
    string $type, string $previousStatus, bool $hasVerifiedPhoto=false, bool $photoChanged=true
): string {
    if ($type === 'photo' && $photoChanged && $hasVerifiedPhoto &&
        in_array($previousStatus, ['ผ่านการตรวจสอบ', 'รอตรวจภาพอีกครั้ง'], true)) {
        return 'รอตรวจภาพอีกครั้ง';
    }
    return 'รอตรวจสอบ';
}
