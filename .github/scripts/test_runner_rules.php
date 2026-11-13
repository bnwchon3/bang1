<?php
declare(strict_types=1);
require __DIR__.'/../../run/runner-rules.php';

function runner_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

foreach (['1234567890123', '0000000000000', 'G123456789012', 'G000000000000'] as $value) {
    runner_assert(bang1_valid_citizen_id($value), 'Expected valid runner ID: '.$value);
}
foreach (['G1234567890123', 'G12345678901', 'A123456789012', '123456789012', 'G12345X789012', 'G 123456789012', ''] as $value) {
    runner_assert(!bang1_valid_citizen_id($value), 'Expected invalid runner ID: '.$value);
}
runner_assert(bang1_normalize_citizen_id(' g123456789012 ') === 'G123456789012', 'Case normalization failed');
runner_assert(bang1_runner_registration_member_count('1, 2,3') === 3, 'Relay member count failed');
runner_assert(bang1_runner_registration_member_count('5') === 1, 'Individual member count failed');
runner_assert(bang1_runner_delete_block_reason(false, false, 0) === null, 'Unregistered athlete must be deletable');
runner_assert(bang1_runner_delete_block_reason(false, false, 1) === null, 'Unscored solo athlete must be deletable');
runner_assert(bang1_runner_delete_block_reason(true, false, 1) !== null, 'Do not erase scored athlete');
runner_assert(bang1_runner_delete_block_reason(false, true, 1) !== null, 'Do not erase checked-in athlete');
runner_assert(bang1_runner_delete_block_reason(false, false, 4) !== null, 'Do not cancel teammate entries');
foreach (['รอตรวจสอบ', 'ผ่านการตรวจสอบ', 'รอตรวจภาพอีกครั้ง', 'ไม่ผ่านการตรวจสอบ'] as $status) {
    runner_assert(bang1_runner_status_after_edit('details',$status,true)==='รอตรวจสอบ','Athlete corrections must re-enter review');
    runner_assert(bang1_runner_status_after_edit('idproof',$status,true)==='รอตรวจสอบ','Identity-proof updates must re-enter review');
}
runner_assert(bang1_runner_status_after_edit('photo','ผ่านการตรวจสอบ',true,true)==='รอตรวจภาพอีกครั้ง','Previously approved photo replacement requires face review');
runner_assert(bang1_runner_status_after_edit('photo','รอตรวจภาพอีกครั้ง',true,true)==='รอตรวจภาพอีกครั้ง','Second replacement requires face review');
runner_assert(bang1_runner_status_after_edit('photo','รอตรวจสอบ',true,true)==='รอตรวจสอบ','Full identity review cannot be bypassed by a new photo');
runner_assert(bang1_runner_status_after_edit('photo','ไม่ผ่านการตรวจสอบ',true,true)==='รอตรวจสอบ','Rejected identity correction requires full review');
runner_assert(bang1_runner_status_after_edit('photo','ผ่านการตรวจสอบ',false,true)==='รอตรวจสอบ','Missing trusted photo requires full verification');
echo "Runner ID, deletion and edit-review rules passed\n";
