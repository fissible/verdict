<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;

/**
 * Opt-in retention for consumed approval payloads. Prune only Consumed receipts after guaranteeing
 * their permanent binding guards, preserving replay refusal after the payload is deleted. A guard
 * store is required; pruning without one must fail closed. The boundary is inclusive
 * (consumed_at <= $consumedBefore), independent of receipt creation and expiry.
 */
interface PrunesConsumedApprovalPayload extends ApprovalReceiptStore
{
    public function pruneConsumedPayload(DateTimeImmutable $consumedBefore): int;
}
