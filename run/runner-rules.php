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
