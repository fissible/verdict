<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;

/**
 * Opt-in retention for approval receipts. Prune only receipts that never admitted an execution:
 * expired Pending, Approved-but-never-consumed, and Rejected rows may go, but Consumed rows must
 * remain because consume() is the single-use gate and deleting one would free its unique binding
 * for a second human-approved execution. The boundary is inclusive (expires_at <= $before).
 */
interface PrunableApprovalReceiptStore extends ApprovalReceiptStore
{
    public function pruneExpired(DateTimeImmutable $before): int;
}
