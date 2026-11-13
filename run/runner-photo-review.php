<?php
declare(strict_types=1);
/**
 * Two-step visual identity verification for athletics entrants.
 *
 * The first successful approval anchors the current photo as the verified
 * reference. A subsequent re-upload requires only a human face comparison
 * with that same previously approved reference, not a second ID document
 * review. Missing/unknown baselines ALWAYS use the full document workflow.
 */
function runner_photo_review_mode(array $runner): string {
    $current=trim((string)($runner['photo_url']??''));
    $verified=trim((string)($runner['verified_photo_url']??''));
    $status=trim((string)($runner['status']??''));
    return $verified!=='' && $current!=='' && $current!==$verified &&
        $status==='รอตรวจภาพอีกครั้ง' ? 'face' : 'document';
}
function runner_photo_can_approve(array $runner): bool {
    if(trim((string)($runner['photo_url']??''))==='')return false;
    return runner_photo_review_mode($runner)==='face' ||
        trim((string)($runner['proof_url']??''))!=='';
}
