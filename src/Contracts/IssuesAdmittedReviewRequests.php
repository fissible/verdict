<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Closure;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewTransition;

/**
 * An opt-in capability beside ReviewRequestStore: a store that can run a caller-supplied hook at
 * the exact clear-to-mint point of issuance, inside the admission-locked critical section.
 *
 * ReviewRequestStore is Stable through 1.0, so this cannot be folded into its signature. It exists
 * for the strict (attested) issuance tier of ADR 0039: the manager must attest a released summary
 * only when the admission check has cleared to mint — never for an Existing/Expired/InvalidState
 * (id-collision) outcome, which admit nothing. The admission decision lives inside the store's
 * transaction, so the attest move has to happen there too, between the check and the persist.
 *
 * A store that has not adopted this interface keeps its existing issue() behaviour unchanged; the
 * manager fails a strict issuance closed rather than reverting to the pre-ADR attest-before-issue
 * order against such a store.
 */
interface IssuesAdmittedReviewRequests
{
    /**
     * Issue a request exactly as issue() does, but invoke $onAdmitted at the clear-to-mint point:
     * after the existing-row and id-collision checks have passed, before the row is persisted,
     * inside the admission-locked transaction. If $onAdmitted throws, the transaction rolls back —
     * nothing is persisted — and the throwable propagates to the caller.
     *
     * $onAdmitted runs once per admission attempt, so a transaction retry re-runs it; its side
     * effects must therefore be idempotent. The request id is fixed on $request and is stable
     * across retries.
     *
     * @param  Closure(): void  $onAdmitted
     */
    public function issueAdmitted(ReviewRequest $request, Closure $onAdmitted): ReviewTransition;
}
