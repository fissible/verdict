<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\RequireReviewNotImplemented;
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\Intents\Events\ActionIntentWriteFailed;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Fissible\Verdict\RateLimits\RateLimitOutcome;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewTransition;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Fissible\Verdict\VerdictManager;
use Illuminate\Support\Facades\Event;

// ADR 0035 §5/§7 — runBound() is the SOLE reviewed-admission path. Unlike run() (issue-and-refuse
// only, 6a-2), runBound() admits an already-approved review through the ReviewManager gate, reusing
// the confirmation lane's validate/refresh/validate/consume ordering (ADR 0003):
//
//   • First bound attempt, no matching request → §7 issues the durable request AT the execution stage
//     (after the target refresh + execution re-decision, and recorded AFTER it) and refuses.
//   • A later attempt whose request an operator APPROVED out of band → admitted ONCE: consume() is the
//     final gate (after intent + rate-limit), the request is Consumed BEFORE the executor runs, and the
//     RECORDED/returned decision stays RequireReview — never rewritten to Permit.
//   • Pending / Rejected / expired / Consumed → refuse GRACEFULLY (no execute, no re-issue, no throw),
//     returning a review-pending RequireReview result. A terminal binding is never reopened (§6).
//   • §5.1 validate runs BEFORE the target refresh: a non-admissible proposal binding refuses without
//     refreshing. §5.2: a fresh policy that no longer requires review is never overridden by an approval.
//   • Any downstream gate (intent, rate-limit) refusing on admission leaves the review UNCONSUMED.
//   • Unconfigured lane → the §1 loud reserve still throws (preserved).
//
// Self-contained: Pest loads sibling files' top-level helpers only when those files run, so admission
// fixtures are defined here with unique names (only tests/Pest.php globals like acceptTestSnapshot are used).

const ADMISSION_REVIEW_PENDING_REASON = 'This action requires human review; a review request is pending a decision.';

/** A durable intent store that always refuses — the fail-closed injection for the intent-ordering test. */
final class AdmissionRefusingIntentStore implements ActionIntentStore
{
    public function record(ActionIntent $intent): void
    {
        throw new RuntimeException('The intent store is unavailable.');
    }

    public function find(string $id): ?ActionIntent
    {
        return null;
    }
}

/** A rate-limit store that always throttles — the fail-closed injection for the rate-limit-ordering test. */
final class AdmissionThrottlingRateLimitStore implements RateLimitStore
{
    public function consume(RateLimitConsumption $consumption): RateLimitOutcome
    {
        return new RateLimitOutcome(
            allowed: false,
            limit: $consumption->limit,
            remaining: 0,
            resetAt: $consumption->at,
        );
    }
}

/**
 * An approved review whose consume() always fails — proves the executor is gated on a successful
 * consume. A delegating decorator (InMemoryReviewRequestStore is final): every method but consume()
 * behaves normally, so validate() still reports Approved right up to the failing consume().
 */
final class AdmissionConsumeFailsStore implements ReviewRequestStore
{
    public function __construct(private InMemoryReviewRequestStore $inner = new InMemoryReviewRequestStore) {}

    public function issue(ReviewRequest $request): ReviewTransition
    {
        return $this->inner->issue($request);
    }

    public function find(string $requestId): ?ReviewRequest
    {
        return $this->inner->find($requestId);
    }

    public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        return $this->inner->approve($requestId, $resolvedBy, $at);
    }

    public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        return $this->inner->reject($requestId, $resolvedBy, $at);
    }

    public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        return $this->inner->validate($capability, $bindingFingerprint, $at);
    }

    public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        return ReviewTransition::to(ReviewOutcome::InvalidState);
    }
}

function admissionEnvelope(string $toolCallId = 'tool-call-1', int $orderId = 7001, string $tenant = 'store-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal('orders.cancel', ['order_id' => $orderId], $toolCallId),
        context: new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => $tenant]),
    );
}

/**
 * The review-gated capability. $executed flips when the executor runs; $statusAtExecution captures the
 * review request's status AS OBSERVED from inside the executor (must already be Consumed — consume precedes
 * execute). $resolveCount counts target resolutions, so a §5.1 pre-refresh refusal is observable.
 */
function admissionCapability(
    ?bool &$executed = null,
    bool $withBinder = true,
    ?ReviewStatus &$statusAtExecution = null,
): Capability {
    $capability = Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments,
    )->executionTarget(acceptTestSnapshot('admission-target'))
        ->executeUsing(function (AuthorizedAction $action) use (&$executed, &$statusAtExecution): string {
            $executed = true;
            $requests = array_values(app(ReviewRequestStore::class)->all());
            $statusAtExecution = $requests === [] ? null : $requests[0]->status;

            return 'cancelled';
        });

    if ($withBinder) {
        $capability = $capability->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => ['bound_order' => $target['order_id']],
            reason: 'Confirm this cancellation.',
        );
    }

    return $capability;
}

function bindAdmissionAuthorizer(callable $decide): void
{
    app()->instance(CapabilityAuthorizer::class, new class($decide) implements CapabilityAuthorizer
    {
        public function __construct(private $decide) {}

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return ($this->decide)($capability, $envelope, $target);
        }
    });
}

function enableAdmissionLane(?ReviewRequestStore $store = null): void
{
    $store ??= new InMemoryReviewRequestStore;
    app()->instance(ReviewRequestStore::class, $store);
    config()->set('verdict.reviews.store', $store::class);
}

function admissionStore(): InMemoryReviewRequestStore
{
    $store = app(ReviewRequestStore::class);
    assert($store instanceof InMemoryReviewRequestStore);

    return $store;
}

/** @return list<DecisionEvidence> */
function admissionEvidence(string $stage): array
{
    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    return array_values(array_filter($recorder->all(), fn (DecisionEvidence $e): bool => $e->stage === $stage));
}

/** The recorder's full event ordering — used to prove one stage was recorded before another. */
function admissionStageOrder(): array
{
    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    return array_map(fn (DecisionEvidence $e): string => $e->stage, $recorder->all());
}

/**
 * Boot a review-gated bound pipeline against a specific capability: a controllable clock, an in-memory
 * review lane, and an authorizer (RequireReview unless $decide overrides). Returns the VerdictManager.
 */
function bootReviewAdmissionWith(Capability $capability, ?Closure $decide = null): VerdictManager
{
    app()->instance(Clock::class, new FrozenClock('2026-08-30 12:00:00'));
    enableAdmissionLane();
    bindAdmissionAuthorizer($decide ?? fn (): Decision => Decision::requireReview('A human must review this cancellation.'));

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    return $verdict;
}

function bootReviewAdmission(bool &$executed, ?Closure $decide = null, bool $withBinder = true): VerdictManager
{
    return bootReviewAdmissionWith(admissionCapability($executed, $withBinder), $decide);
}

function admissionClock(): FrozenClock
{
    $clock = app(Clock::class);
    assert($clock instanceof FrozenClock);

    return $clock;
}

/** First bound attempt against a review-gated action: assert it refused-and-issued, return the request id. */
function issueViaBound(VerdictManager $verdict, ActionEnvelope $envelope): string
{
    $result = $verdict->runBound($envelope);
    $requestId = $result->evaluation->decision->metadata['review_request_id'] ?? null;

    expect($result->executed)->toBeFalse()
        ->and($requestId)->toBeString();

    return $requestId;
}

/** Approve the pending request out of band, directly on the durable store (as an operator would). */
function approveOutOfBand(string $requestId, string $by = 'reviewer-7'): void
{
    admissionStore()->approve($requestId, $by, admissionClock()->now());
}

// ── §7 issuance-at-execution on a first bound attempt ─────────────────────────────────────────────

it('issues the review at the execution stage and refuses on a first bound attempt, executing nothing', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $result = $verdict->runBound(admissionEnvelope());
    $stored = admissionStore()->all();
    $requestId = $result->evaluation->decision->metadata['review_request_id'] ?? null;

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        ->and($result->evaluation->decision->reason)->toBe(ADMISSION_REVIEW_PENDING_REASON) // the defined pending shape
        ->and($requestId)->toBeString()
        ->and($stored)->toHaveCount(1)
        ->and($stored[$requestId]->status)->toBe(ReviewStatus::Pending)
        // …correlated durably, fingerprint-only, at the review stage.
        ->and(admissionEvidence('review'))->toHaveCount(1)
        ->and(admissionEvidence('review')[0]->reviewRequestFingerprint)->toBe($stored[$requestId]->bindingFingerprint);

    // Issuance is AT execution stage, after a full refresh → re-decide: the stages are recorded in the ADR
    // order target_refresh < execution < review (the refresh precedes the execution re-decision, §5.2, and
    // issuance follows both, §7). Every index must EXIST — array_search() returns false when absent, and
    // false < n is truthy in PHP, so a missing stage would slip past a bare comparison.
    $order = admissionStageOrder();
    $refreshIndex = array_search('target_refresh', $order, true);
    $executionIndex = array_search('execution', $order, true);
    $reviewIndex = array_search('review', $order, true);
    expect($refreshIndex)->toBeInt()
        ->and($executionIndex)->toBeInt()
        ->and($reviewIndex)->toBeInt()
        ->and($refreshIndex)->toBeLessThan($executionIndex)
        ->and($executionIndex)->toBeLessThan($reviewIndex);
});

// ── §5 admission of an approved review ────────────────────────────────────────────────────────────

it('admits an approved review exactly once — executes with the request already consumed, decision still require_review', function (): void {
    $executed = false;
    $statusAtExecution = null;
    $verdict = bootReviewAdmissionWith(admissionCapability($executed, true, $statusAtExecution));

    $requestId = issueViaBound($verdict, admissionEnvelope());
    approveOutOfBand($requestId);

    $result = $verdict->runBound(admissionEnvelope());

    expect($result->executed)->toBeTrue()
        ->and($executed)->toBeTrue()
        ->and($result->output)->toBe('cancelled')
        // consume precedes execute: the request was already Consumed when the executor observed it.
        ->and($statusAtExecution)->toBe(ReviewStatus::Consumed)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Consumed)
        // §5's invariant: the returned/recorded decision stays require_review — never a rewritten Permit,
        // not even only-for-downstream. The admitted authority is a separate outcome, not the policy result.
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        ->and(admissionEvidence('execution'))->not->toBeEmpty()
        ->and(collect(admissionEvidence('execution'))->pluck('disposition')->all())->not->toContain('permit');
});

it('refuses a second admission after the review is consumed — single-use, no re-execution, no new request', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope());
    approveOutOfBand($requestId);
    $verdict->runBound(admissionEnvelope()); // admits once → Consumed

    $executed = false; // reset the observation; the second admission must not run the executor again
    $second = $verdict->runBound(admissionEnvelope());

    expect($second->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($second->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        // The consumed request is not reopened, and no fresh request is minted for the spent binding.
        ->and(admissionStore()->all())->toHaveCount(1)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Consumed);
});

// ── binding matching — an approval is bound, never capability-wide ─────────────────────────────────

it('does not admit a different bound action against an approval granted for another binding', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $approvedId = issueViaBound($verdict, admissionEnvelope(orderId: 7001)); // approve order 7001
    approveOutOfBand($approvedId);

    // A different order under the same capability must NOT ride 7001's approval: it issues its own request.
    $other = $verdict->runBound(admissionEnvelope(toolCallId: 'tool-call-2', orderId: 9009));

    expect($other->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and(admissionStore()->all())->toHaveCount(2) // 7001 (Approved) + a fresh 9009 (Pending)
        ->and(admissionStore()->find($approvedId)?->status)->toBe(ReviewStatus::Approved); // untouched, unconsumed
});

// ── refusals that must NOT execute, re-issue, or throw ────────────────────────────────────────────

it('refuses a rejected review and never reopens or re-issues it', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope());
    admissionStore()->reject($requestId, 'reviewer-9', admissionClock()->now());

    $result = $verdict->runBound(admissionEnvelope()); // must refuse gracefully, not throw

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview) // review-pending refusal
        // §6: a Rejected binding stays refused — same request, still Rejected, no second request.
        ->and(admissionStore()->all())->toHaveCount(1)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Rejected);
});

it('keeps refusing while the review is still pending, without issuing a duplicate', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope());   // issues a Pending request
    $again = $verdict->runBound(admissionEnvelope());            // still pending → refuse, idempotent

    expect($again->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($again->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        ->and(admissionStore()->all())->toHaveCount(1)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Pending);
});

it('refuses an approved review that has since expired, admitting and consuming nothing', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope());
    approveOutOfBand($requestId);                             // approved at 12:00, TTL 900s → expires 12:15

    admissionClock()->time = new DateTimeImmutable('2026-08-30 12:20:00', new DateTimeZone('UTC')); // past expiry
    $result = $verdict->runBound(admissionEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview)
        // §6: expiry admits and mutates nothing, and the same binding is not reissued.
        ->and(admissionStore()->all())->toHaveCount(1)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Approved);
});

// ── §5.1 the validate runs BEFORE the target refresh ──────────────────────────────────────────────

it('refuses a non-admissible proposal binding before refreshing the target (§5.1 preflight)', function (): void {
    // A Rejected binding must be caught by the pre-refresh validate: the re-attempt records NO new
    // target_refresh — proving the gate validated before (not after) the refresh, per §5.1.
    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope());     // first attempt DOES refresh (1 record)
    admissionStore()->reject($requestId, 'reviewer-9', admissionClock()->now());
    expect(admissionEvidence('target_refresh'))->toHaveCount(1);

    $verdict->runBound(admissionEnvelope());                       // rejected → refuse before refresh

    expect($executed)->toBeFalse()
        ->and(admissionEvidence('target_refresh'))->toHaveCount(1); // still 1 — no refresh on the refused re-attempt
});

// ── §5.2 the review gate never overrides a fresh policy decision ──────────────────────────────────

it('does not admit an approved review when the fresh execution policy now denies — approval untouched', function (): void {
    // A granted review is not a standing authorization. If policy has hardened to Deny by the admit
    // attempt, the action is denied and the approval is NOT spent.
    $policy = new class
    {
        public bool $deny = false;

        public function decide(): Decision
        {
            return $this->deny
                ? Decision::deny('No longer permitted.')
                : Decision::requireReview('A human must review this cancellation.');
        }
    };
    $executed = false;
    $verdict = bootReviewAdmission($executed, fn (): Decision => $policy->decide());

    $requestId = issueViaBound($verdict, admissionEnvelope());
    approveOutOfBand($requestId);
    $policy->deny = true; // policy hardens before the admit attempt

    $result = $verdict->runBound(admissionEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::Deny)
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Approved); // never consumed
});

// ── ADR 0003 ordering: consume is the LAST gate, after BOTH intent and rate-limit ─────────────────

it('does not consume the review when the intent gate refuses on admission — fail-closed', function (): void {
    // The intent gate (#160) runs before consume. Force its durable write to fail on the admit attempt:
    // denied at the intent stage, the approved review is NOT consumed — consume is sequenced after intent.
    config()->set('verdict.intents.required', true);
    config()->set('verdict.intents.store', AdmissionRefusingIntentStore::class);
    app()->forgetInstance(ActionIntentStore::class);
    Event::fake([ActionIntentWriteFailed::class]);

    $executed = false;
    $verdict = bootReviewAdmission($executed);

    $requestId = issueViaBound($verdict, admissionEnvelope()); // issuance precedes the intent gate, so it still issues
    approveOutOfBand($requestId);

    $denied = $verdict->runBound(admissionEnvelope());

    expect($denied->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($denied->evaluation->stage->value)->toBe('intent')
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Approved); // approval preserved
    Event::assertDispatched(ActionIntentWriteFailed::class);
});

it('does not consume the review when the rate-limit gate refuses on admission — fail-closed', function (): void {
    // The rate-limit gate runs after intent and before consume. Throttle it on the admit attempt: denied
    // at the rate-limit stage, the approved review is NOT consumed — consume is sequenced after rate-limit.
    config()->set('verdict.rate_limits.store', AdmissionThrottlingRateLimitStore::class);
    app()->instance(RateLimitStore::class, new AdmissionThrottlingRateLimitStore);

    $executed = false;
    $verdict = bootReviewAdmissionWith(admissionCapability($executed)->rateLimit(RateLimitPolicy::fixedWindow(
        name: 'admission-limit',
        limit: 1,
        windowSeconds: 60,
        keyUsing: fn (ActionEnvelope $envelope, mixed $target): array => ['actor' => $envelope->context->actor],
        reason: 'Rate limit exceeded.',
    )));

    $requestId = issueViaBound($verdict, admissionEnvelope()); // issuance precedes the rate-limit gate
    approveOutOfBand($requestId);

    $denied = $verdict->runBound(admissionEnvelope());

    expect($denied->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($denied->evaluation->stage->value)->toBe('rate_limit')
        ->and(admissionStore()->find($requestId)?->status)->toBe(ReviewStatus::Approved); // approval preserved
});

it('refuses without executing when consume() fails after a passing validate — the executor is gated on consume', function (): void {
    // An atomic-race guard: validate() reports Approved, but consume() then fails (another worker won the
    // race, or the row lapsed). Execution must be gated on the SUCCESSFUL consume, never on the validate.
    $executed = false;
    $store = new AdmissionConsumeFailsStore; // validate() Approved (delegated), consume() → InvalidState
    app()->instance(Clock::class, new FrozenClock('2026-08-30 12:00:00'));
    enableAdmissionLane($store);
    bindAdmissionAuthorizer(fn (): Decision => Decision::requireReview('A human must review this cancellation.'));
    $verdict = app(VerdictManager::class);
    $verdict->capability(admissionCapability($executed));

    $issued = $verdict->runBound(admissionEnvelope());
    $requestId = $issued->evaluation->decision->metadata['review_request_id'];
    $store->approve($requestId, 'reviewer-7', admissionClock()->now());

    $result = $verdict->runBound(admissionEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::RequireReview);
});

// ── the loud reserve is preserved on runBound when no review lane is configured ───────────────────

it('runBound() still throws the loud reserve when no review lane is configured', function (): void {
    // With a review lane configured, runBound now issues/admits; with none, the §1 loud reserve holds.
    bindAdmissionAuthorizer(fn (): Decision => Decision::requireReview('A human must review this cancellation.'));
    $verdict = app(VerdictManager::class);
    $verdict->capability(admissionCapability());

    expect(fn (): mixed => $verdict->runBound(admissionEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);
});

// ── non-review dispositions are untouched by the admission wiring ─────────────────────────────────

it('still admits a permitted bound action normally with a review lane configured, consuming no review', function (): void {
    $executed = false;
    $verdict = bootReviewAdmission($executed, fn (): Decision => Decision::permit(), withBinder: false);

    $result = $verdict->runBound(admissionEnvelope());

    expect($result->executed)->toBeTrue()
        ->and($executed)->toBeTrue()
        ->and($result->evaluation->decision->disposition)->toBe(Disposition::Permit)
        ->and(admissionStore()->all())->toBe([]);
});
