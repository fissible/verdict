<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeImmutable;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewTransition;

/**
 * Operational state for out-of-band human review, keyed two ways with different guarantees:
 * decisions (approve/reject) address a request by its id, while execution-side checks
 * (validate/consume) address it by capability + binding fingerprint. Custom implementations are
 * a supported extension point; the invariants below are what the review gate depends on.
 */
interface ReviewRequestStore
{
    /**
     * Idempotent per (capability, bindingFingerprint): re-issuing the same binding returns the
     * canonical Existing request rather than a second request. A reused id for another binding is
     * InvalidState and must never overwrite the original request.
     */
    public function issue(ReviewRequest $request): ReviewTransition;

    /**
     * The request with this id, or null. Ids are unique, so this is never ambiguous; a decision
     * authorizer reads the request here before resolving it and must see exactly the request
     * approve()/reject() would transition.
     */
    public function find(string $requestId): ?ReviewRequest;

    /**
     * Finalize by request id; the store owns the canonical failure outcomes
     * (NotFound/Expired/InvalidState) and the transition must be atomic. A decision is admissible
     * only when, at $at, the identified request is Pending and unexpired; every other request must
     * be refused with the applicable failure outcome.
     */
    public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition;

    /** Finalize by request id, with the same admissibility and atomicity guarantees as approve(). */
    public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition;

    /** Execution-side check by capability + binding fingerprint; does not mutate the request. */
    public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition;

    /** Single-use execution admission by capability + binding fingerprint; atomic. */
    public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition;
}
