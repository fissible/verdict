<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Exceptions\ReviewAuthorizerMissing;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewDecisionKind;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewTransition;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;

// ADR 0035 §3 — the review lane's decision authorizer, and where it is enforced. The review store is
// not a security boundary (any id-holder can mutate it); ReviewManager is. approve()/reject() are
// FAIL-CLOSED: with no authorizer configured they refuse outright, because "resolvedBy" is
// attestation-by-the-application and the authorizer is where the application makes it mean something,
// keying on the request's immutable approvalContext.
//
// The load-bearing subtlety is the ADR 0036 / #320 lesson applied to the review lane: state is resolved
// BEFORE authorization, so a terminal or expired request is refused with the store's canonical outcome
// (InvalidState / Expired / NotFound) and NEVER reaches the authorizer's authorize() — Unauthorized must
// not mask a request that was already undecidable. A spy authorizer proves which requests reach it; a
// recording store proves the manager DELEGATES the transition rather than reimplementing the state machine.

final class SpyReviewAuthorizer implements ReviewDecisionAuthorizer
{
    public bool $allow = true;

    /** @var list<array{request: ReviewRequest, kind: ReviewDecisionKind, decidedBy: string}> */
    public array $calls = [];

    public function authorize(ReviewRequest $request, ReviewDecisionKind $kind, string $decidedBy): bool
    {
        $this->calls[] = ['request' => $request, 'kind' => $kind, 'decidedBy' => $decidedBy];

        return $this->allow;
    }
}

/** A store fake that records decision calls and returns a unique sentinel — for proving delegation. */
final class RecordingReviewStore implements ReviewRequestStore
{
    public ?ReviewRequest $found;

    public ReviewTransition $sentinel;

    /** @var list<array{id: string, by: string, at: DateTimeImmutable}> */
    public array $approveCalls = [];

    /** @var list<array{id: string, by: string, at: DateTimeImmutable}> */
    public array $rejectCalls = [];

    public function __construct()
    {
        $this->found = pendingReviewRequest();
        $this->sentinel = ReviewTransition::to(ReviewOutcome::Approved, pendingReviewRequest());
    }

    public function issue(ReviewRequest $request): ReviewTransition
    {
        throw new RuntimeException('unused in decision tests');
    }

    public function find(string $requestId): ?ReviewRequest
    {
        return $this->found;
    }

    public function approve(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        $this->approveCalls[] = ['id' => $requestId, 'by' => $resolvedBy, 'at' => $at];

        return $this->sentinel;
    }

    public function reject(string $requestId, string $resolvedBy, DateTimeImmutable $at): ReviewTransition
    {
        $this->rejectCalls[] = ['id' => $requestId, 'by' => $resolvedBy, 'at' => $at];

        return $this->sentinel;
    }

    public function validate(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        throw new RuntimeException('unused in decision tests');
    }

    public function consume(string $capability, string $bindingFingerprint, DateTimeImmutable $at): ReviewTransition
    {
        throw new RuntimeException('unused in decision tests');
    }
}

function reviewClock(): FrozenClock
{
    return new FrozenClock('2026-08-30 12:30:00');
}

function pendingReviewRequest(string $expiresAt = '2026-08-30 13:00:00'): ReviewRequest
{
    return ReviewRequest::pending(
        id: 'rev_1',
        capability: 'orders.cancel',
        bindingFingerprint: 'bind-abc',
        approvalContext: ['tenant_id' => 'store-1'],
        createdAt: new DateTimeImmutable('2026-08-30 12:00:00'),
        expiresAt: new DateTimeImmutable($expiresAt),
        reason: 'A human must review this cancellation.',
    );
}

function seedPendingReview(ReviewRequestStore $store, string $expiresAt = '2026-08-30 13:00:00'): void
{
    $store->issue(pendingReviewRequest($expiresAt));
}

dataset('decisionMethod', ['approve', 'reject']);

// ── fail-closed: no authorizer configured ────────────────────────────────────────────────────────

it('is fail-closed with no authorizer, for both decision methods', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $manager = new ReviewManager($store, reviewClock(), null);

    expect(fn (): mixed => $manager->$method('rev_1', 'reviewer-7'))->toThrow(ReviewAuthorizerMissing::class)
        ->and($store->find('rev_1')?->status)->toBe(ReviewStatus::Pending);
})->with('decisionMethod');

it('is fail-closed even for an unknown request — no authorizer means no decisions at all', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    $manager = new ReviewManager($store, reviewClock(), null);

    expect(fn (): mixed => $manager->$method('nope', 'reviewer-7'))->toThrow(ReviewAuthorizerMissing::class);
})->with('decisionMethod');

it('is fail-closed even for a terminal request — a direct-null authorizer throws before the state outcome', function (string $method): void {
    // Fail-closed precedes state resolution: a directly-configured null authorizer must throw
    // ReviewAuthorizerMissing rather than returning the store's InvalidState for an already-decided request.
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $store->reject('rev_1', 'reviewer-9', new DateTimeImmutable('2026-08-30 12:10:00'));
    $manager = new ReviewManager($store, reviewClock(), null);

    expect(fn (): mixed => $manager->$method('rev_1', 'reviewer-7'))->toThrow(ReviewAuthorizerMissing::class);
})->with('decisionMethod');

// ── input validation precedes everything, including the fail-closed resolution ────────────────────

it('requires a request id and a decider, for both methods', function (string $method): void {
    $manager = new ReviewManager(new InMemoryReviewRequestStore, reviewClock(), new SpyReviewAuthorizer);

    expect(fn (): mixed => $manager->$method('', 'reviewer-7'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('  ', 'reviewer-7'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('rev_1', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('rev_1', '   '))->toThrow(InvalidArgumentException::class);
})->with('decisionMethod');

it('validates input before it consults the fail-closed authorizer resolution', function (string $method): void {
    // Blank/whitespace input with a NULL authorizer must be an InvalidArgumentException, not
    // ReviewAuthorizerMissing — the input guard runs first (mirrors ApprovalManager), and it guards BOTH
    // the request id and the decider (an implementation could otherwise fail-close before checking decidedBy).
    $manager = new ReviewManager(new InMemoryReviewRequestStore, reviewClock(), null);

    expect(fn (): mixed => $manager->$method('', 'reviewer-7'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('  ', 'reviewer-7'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('rev_1', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $manager->$method('rev_1', '   '))->toThrow(InvalidArgumentException::class);
})->with('decisionMethod');

// ── authorized decisions reach the store ─────────────────────────────────────────────────────────

it('approves a decidable request when the authorizer allows it, stamping the actor and clock', function (): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    $transition = $manager->approve('rev_1', 'reviewer-7');
    $stored = $store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Approved)
        ->and($stored?->status)->toBe(ReviewStatus::Approved)
        ->and($stored?->resolvedBy)->toBe('reviewer-7')
        ->and($stored?->resolvedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'))
        ->and($authorizer->calls)->toHaveCount(1);
});

it('rejects a decidable request when the authorizer allows it', function (): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $manager = new ReviewManager($store, reviewClock(), new SpyReviewAuthorizer);

    $transition = $manager->reject('rev_1', 'reviewer-9');

    expect($transition->outcome)->toBe(ReviewOutcome::Rejected)
        ->and($store->find('rev_1')?->status)->toBe(ReviewStatus::Rejected)
        ->and($store->find('rev_1')?->resolvedBy)->toBe('reviewer-9')
        ->and($store->find('rev_1')?->resolvedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'));
});

it('hands the authorizer the request, the per-method decision kind, and the decider', function (string $method, ReviewDecisionKind $kind): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    $manager->$method('rev_1', 'reviewer-7');
    $call = $authorizer->calls[0];

    expect($authorizer->calls)->toHaveCount(1)
        ->and($call['request']->id)->toBe('rev_1')
        ->and($call['request']->approvalContext)->toBe(['tenant_id' => 'store-1'])
        ->and($call['kind'])->toBe($kind)
        ->and($call['decidedBy'])->toBe('reviewer-7');
})->with([
    'approve' => ['approve', ReviewDecisionKind::Approve],
    'reject' => ['reject', ReviewDecisionKind::Reject],
]);

// ── denied decisions never mutate and never delegate ─────────────────────────────────────────────

it('returns Unauthorized and leaves the request wholly unresolved when denied', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $authorizer = new SpyReviewAuthorizer;
    $authorizer->allow = false;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    $transition = $manager->$method('rev_1', 'stranger');
    $stored = $store->find('rev_1');

    expect($transition->outcome)->toBe(ReviewOutcome::Unauthorized)
        ->and($authorizer->calls)->toHaveCount(1)
        ->and($stored?->status)->toBe(ReviewStatus::Pending)
        ->and($stored?->resolvedBy)->toBeNull()
        ->and($stored?->resolvedAt)->toBeNull();
})->with('decisionMethod');

it('makes zero store decision calls when the authorizer denies', function (string $method): void {
    $store = new RecordingReviewStore; // find() returns a decidable pending request
    $authorizer = new SpyReviewAuthorizer;
    $authorizer->allow = false;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    $manager->$method('rev_1', 'stranger');

    expect($store->approveCalls)->toBeEmpty()
        ->and($store->rejectCalls)->toBeEmpty();
})->with('decisionMethod');

// ── delegation: the manager returns exactly what the store returns and forwards the call ──────────

it('delegates an authorized decision to the store verbatim, forwarding id, actor, and clock', function (string $method, string $callsProp): void {
    $store = new RecordingReviewStore;
    $manager = new ReviewManager($store, reviewClock(), new SpyReviewAuthorizer);

    $transition = $manager->$method('rev_1', 'reviewer-7');

    // The manager returns the store's exact transition object — it does not rewrap or reimplement it.
    expect($transition)->toBe($store->sentinel)
        ->and($store->$callsProp)->toHaveCount(1)
        ->and($store->$callsProp[0]['id'])->toBe('rev_1')
        ->and($store->$callsProp[0]['by'])->toBe('reviewer-7')
        ->and($store->$callsProp[0]['at'])->toEqual(new DateTimeImmutable('2026-08-30 12:30:00'));
})->with([
    'approve' => ['approve', 'approveCalls'],
    'reject' => ['reject', 'rejectCalls'],
]);

// ── the ADR 0036 / #320 discipline: state precedes authorization ─────────────────────────────────

it('never consults the authorizer for a consumed request — InvalidState is not masked', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $store->approve('rev_1', 'reviewer-7', new DateTimeImmutable('2026-08-30 12:10:00'));
    $store->consume('orders.cancel', 'bind-abc', new DateTimeImmutable('2026-08-30 12:20:00'));
    $authorizer = new SpyReviewAuthorizer;
    $authorizer->allow = false; // even a denying authorizer must not be reached
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    $transition = $manager->$method('rev_1', 'reviewer-7');

    expect($transition->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($authorizer->calls)->toBeEmpty();
})->with('decisionMethod');

it('never consults the authorizer for a rejected request', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $store->reject('rev_1', 'reviewer-9', new DateTimeImmutable('2026-08-30 12:10:00'));
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    expect($manager->$method('rev_1', 'reviewer-7')->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($authorizer->calls)->toBeEmpty();
})->with('decisionMethod');

it('never consults the authorizer for an expired request — Expired is not masked', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store, expiresAt: '2026-08-30 12:15:00'); // clock is 12:30 → past expiry
    $authorizer = new SpyReviewAuthorizer;
    $authorizer->allow = false;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    expect($manager->$method('rev_1', 'reviewer-7')->outcome)->toBe(ReviewOutcome::Expired)
        ->and($authorizer->calls)->toBeEmpty()
        ->and($store->find('rev_1')?->status)->toBe(ReviewStatus::Pending);
})->with('decisionMethod');

it('treats the expiry instant itself as undecidable (>= boundary), skipping the authorizer', function (string $method): void {
    // Expiry preflight must be >= like the value object, not >; at exactly the deadline the request is expired.
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store, expiresAt: '2026-08-30 12:30:00'); // == clock
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    expect($manager->$method('rev_1', 'reviewer-7')->outcome)->toBe(ReviewOutcome::Expired)
        ->and($authorizer->calls)->toBeEmpty();
})->with('decisionMethod');

it('never consults the authorizer for an unknown request — NotFound stands', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), $authorizer);

    expect($manager->$method('nope', 'reviewer-7')->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($authorizer->calls)->toBeEmpty();
})->with('decisionMethod');

// ── deferred (Closure) authorizer resolution ─────────────────────────────────────────────────────

it('does not resolve a Closure authorizer until a decision is attempted, then once', function (): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $resolved = 0;
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), function () use (&$resolved, $authorizer): ReviewDecisionAuthorizer {
        $resolved++;

        return $authorizer;
    });

    expect($resolved)->toBe(0); // construction alone resolves nothing

    $manager->approve('rev_1', 'reviewer-7');

    expect($resolved)->toBe(1)
        ->and($authorizer->calls)->toHaveCount(1);
});

it('resolves the Closure to enforce fail-closed even for a terminal request, without calling authorize()', function (): void {
    // The Closure is resolved first (to enforce fail-closed), but the resolved authorizer's authorize()
    // must still be skipped for an undecidable request — the two are distinct steps.
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $store->reject('rev_1', 'reviewer-9', new DateTimeImmutable('2026-08-30 12:10:00'));
    $resolved = 0;
    $authorizer = new SpyReviewAuthorizer;
    $manager = new ReviewManager($store, reviewClock(), function () use (&$resolved, $authorizer): ReviewDecisionAuthorizer {
        $resolved++;

        return $authorizer;
    });

    expect($manager->approve('rev_1', 'reviewer-7')->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($resolved)->toBe(1)              // the Closure was resolved
        ->and($authorizer->calls)->toBeEmpty(); // but authorize() was not called
});

it('is fail-closed when a Closure authorizer resolves to null, for both methods', function (string $method): void {
    $store = new InMemoryReviewRequestStore;
    seedPendingReview($store);
    $manager = new ReviewManager($store, reviewClock(), fn (): ?ReviewDecisionAuthorizer => null);

    expect(fn (): mixed => $manager->$method('rev_1', 'reviewer-7'))->toThrow(ReviewAuthorizerMissing::class);
})->with('decisionMethod');

// ── the testing authorizer ───────────────────────────────────────────────────────────────────────

it('the AllowAllReviewAuthorizer authorizes any decision', function (): void {
    $authorizer = new AllowAllReviewAuthorizer;
    $request = pendingReviewRequest();

    expect($authorizer->authorize($request, ReviewDecisionKind::Approve, 'anyone'))->toBeTrue()
        ->and($authorizer->authorize($request, ReviewDecisionKind::Reject, 'anyone'))->toBeTrue();
});
