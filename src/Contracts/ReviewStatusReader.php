<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Reviews\ReviewStatusView;

/**
 * The review read contract (ADR 0035 §4): a narrow, observational read seam over review-request
 * state, separate from the write/transition ReviewRequestStore, which is not widened for reads.
 *
 * Freshness is poll-consistency: a read reflects every transition committed before the read
 * began; nothing pushes. Reads carry no authority — approve(), reject(), and consume() each
 * re-validate status and expiry in their transition, so a stale read cannot cause a wrong
 * transition. Expiry is the consumer's clock comparison, never a reported status.
 */
interface ReviewStatusReader
{
    /** The status view of the request with this id, or null when the store has none. */
    public function statusFor(string $requestId): ?ReviewStatusView;

    /**
     * The requests whose persisted lifecycle status is Pending and whose approval_context
     * contains every scope pair with the same typed canonical value. An empty scope throws
     * InvalidArgumentException. Requests with null or empty context never enumerate. A
     * lapsed-but-undecided request is still returned with its expiresAt — expiry is the
     * consumer's clock comparison, never a reported status. Deterministic order: createdAt
     * ascending at second precision, then requestId.
     *
     * @param  non-empty-array<string, string|int>  $scope
     * @return list<ReviewStatusView>
     */
    public function pendingWithin(array $scope): array;
}
