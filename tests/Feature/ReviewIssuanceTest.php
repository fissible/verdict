<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;

// ADR 0035 §3/§7 — review issuance. When a decision resolves to RequireReview, ReviewManager::issue()
// mints a durable ReviewRequest bound to the SAME material the confirmation lane binds (ApprovalManager::
// fingerprint()'s form: capability, execution-target policy, arguments, application binding, and
// approval_context), so the later gate's validate/consume (§5) match this issuance. It mirrors
// ApprovalManager::issue(): a capability with no application binding cannot be fingerprinted → InvalidState.
// It is fail-closed on the disposition — only a RequireReview evaluation mints review state. Provenance and
// the #306 approver summary are materialized in later slices (#201/#306); this slice records neither.
// Issuance is idempotent per binding — the store returns the existing request rather than a duplicate.

/**
 * The application binding deliberately differs in SHAPE from the proposal arguments, so a fingerprint that
 * copied arguments into `binding` (instead of calling approvalBinding()) is distinguishable and fails.
 */
function reviewIssuanceBinding(array $arguments): array
{
    return ['bound_order' => $arguments['order_id']];
}

function reviewIssuanceEvaluation(
    ActionContext $context,
    array $arguments = ['order_id' => 1001],
    string $capabilityName = 'orders.cancel',
    ?string $reviewReason = 'A human must review this cancellation.',
    bool $withBinder = true,
    string $targetPolicy = 'review-target',
    ?int $ttlSeconds = null,
    ?Decision $decision = null,
    ?array $bindingOverride = null,
): Evaluation {
    $capability = Capability::usingPolicy(
        $capabilityName,
        'update',
        fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments,
    )->executionTarget(acceptTestSnapshot($targetPolicy));

    if ($withBinder) {
        $capability = $capability->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => $bindingOverride ?? reviewIssuanceBinding($target),
            reason: 'Confirm this action.',
            ttlSeconds: $ttlSeconds,
        );
    }

    $envelope = ActionEnvelope::wrap(
        new ActionProposal($capabilityName, $arguments, 'tool-call-1'),
        $context,
    );

    return new Evaluation(
        $envelope,
        $capability,
        $arguments,
        $decision ?? Decision::requireReview($reviewReason),
        EvaluationStage::Proposal,
    );
}

function reviewIssuanceManager(InMemoryReviewRequestStore $store, int $ttlSeconds = 900): ReviewManager
{
    return new ReviewManager($store, new FrozenClock('2026-08-30 12:00:00'), new AllowAllReviewAuthorizer, $ttlSeconds);
}

/** The binding fingerprint the confirmation lane's form produces for this evaluation. */
function expectedReviewFingerprint(
    array $arguments,
    array $approvalContext,
    string $targetPolicy = 'review-target',
): string {
    $payload = [
        'capability' => 'orders.cancel',
        'execution_target_policy' => $targetPolicy,
        'arguments' => $arguments,
        'binding' => reviewIssuanceBinding($arguments),
    ];

    if ($approvalContext !== []) {
        $payload['approval_context'] = $approvalContext;
    }

    return ArgumentFingerprint::make($payload);
}

it('issues a pending review request bound to the confirmation-lane fingerprint form', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);
    $context = new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']);

    $transition = $manager->issue(reviewIssuanceEvaluation($context));
    $stored = $transition->request;

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($stored?->status)->toBe(ReviewStatus::Pending)
        ->and($stored?->capability)->toBe('orders.cancel')
        ->and($stored?->bindingFingerprint)->toBe(expectedReviewFingerprint(['order_id' => 1001], ['tenant_id' => 'store-1']))
        ->and($stored?->approvalContext)->toBe(['tenant_id' => 'store-1'])
        ->and($stored?->reason)->toBe('A human must review this cancellation.')
        ->and($stored?->id)->not->toBeEmpty()
        // The minted state carries no resolution yet.
        ->and($stored?->resolvedBy)->toBeNull()
        ->and($stored?->resolvedAt)->toBeNull()
        ->and($stored?->consumedAt)->toBeNull()
        ->and($store->find($stored->id))->not->toBeNull();
});

it('derives the binding from approvalBinding(), not from the proposal arguments', function (): void {
    // The binder returns a differently-SHAPED value than the arguments; the stored fingerprint must reflect
    // approvalBinding()'s output, so a fingerprint that reused $arguments as the binding fails here.
    $store = new InMemoryReviewRequestStore;
    $context = new ActionContext(actor: 'customer:72');

    $stored = reviewIssuanceManager($store)->issue(reviewIssuanceEvaluation($context))->request;

    // Cross-check: a fingerprint computed with arguments-as-binding is DIFFERENT and must not match.
    $argumentsAsBinding = ArgumentFingerprint::make([
        'capability' => 'orders.cancel',
        'execution_target_policy' => 'review-target',
        'arguments' => ['order_id' => 1001],
        'binding' => ['order_id' => 1001],
    ]);

    expect($stored?->bindingFingerprint)->toBe(expectedReviewFingerprint(['order_id' => 1001], []))
        ->and($stored?->bindingFingerprint)->not->toBe($argumentsAsBinding);
});

it('changes the fingerprint when the execution-target policy differs', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);
    $context = new ActionContext(actor: 'customer:72');

    $a = $manager->issue(reviewIssuanceEvaluation($context, targetPolicy: 'policy-a'))->request;
    $b = $manager->issue(reviewIssuanceEvaluation($context, targetPolicy: 'policy-b'))->request;

    expect($a?->bindingFingerprint)->not->toBe($b?->bindingFingerprint)
        ->and(count($store->all()))->toBe(2);
});

it('changes the fingerprint when the binding differs, with identical arguments', function (): void {
    // Same arguments/context/policy, but the application binding resolves to different values — the
    // binding component participates independently, so this must yield two distinct requests.
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);
    $context = new ActionContext(actor: 'customer:72');

    $a = $manager->issue(reviewIssuanceEvaluation($context, bindingOverride: ['seat' => 'A1']))->request;
    $b = $manager->issue(reviewIssuanceEvaluation($context, bindingOverride: ['seat' => 'B2']))->request;

    expect($a?->bindingFingerprint)->not->toBe($b?->bindingFingerprint)
        ->and(count($store->all()))->toBe(2);
});

it('computes expiresAt from the clock plus the default TTL', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store, ttlSeconds: 600); // 10 minutes
    $context = new ActionContext(actor: 'customer:72');

    $stored = $manager->issue(reviewIssuanceEvaluation($context))->request;

    expect($stored?->createdAt)->toEqual(new DateTimeImmutable('2026-08-30 12:00:00'))
        ->and($stored?->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 12:10:00'));
});

it('lets a capability TTL override the manager default', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store, ttlSeconds: 600); // default 10 min
    $context = new ActionContext(actor: 'customer:72');

    // The capability declares a 120s TTL, which must win over the manager default.
    $stored = $manager->issue(reviewIssuanceEvaluation($context, ttlSeconds: 120))->request;

    expect($stored?->expiresAt)->toEqual(new DateTimeImmutable('2026-08-30 12:02:00'));
});

it('records neither provenance nor approver summary at this slice', function (): void {
    $store = new InMemoryReviewRequestStore;
    $stored = reviewIssuanceManager($store)->issue(
        reviewIssuanceEvaluation(new ActionContext(actor: 'customer:72'))
    )->request;

    expect($stored?->provenance)->toBeNull()
        ->and($stored?->approverSummary)->toBeNull();
});

it('is idempotent per binding — re-issuing the same evaluation returns the existing request', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);
    $context = new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']);

    $first = $manager->issue(reviewIssuanceEvaluation($context));
    $second = $manager->issue(reviewIssuanceEvaluation($context));

    expect($first->outcome)->toBe(ReviewOutcome::Issued)
        ->and($second->outcome)->toBe(ReviewOutcome::Existing)
        ->and($second->request?->id)->toBe($first->request?->id)
        ->and(count($store->all()))->toBe(1);
});

it('binds different arguments to different requests', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);
    $context = new ActionContext(actor: 'customer:72');

    $manager->issue(reviewIssuanceEvaluation($context, arguments: ['order_id' => 1001]));
    $second = $manager->issue(reviewIssuanceEvaluation($context, arguments: ['order_id' => 2002]));

    expect($second->outcome)->toBe(ReviewOutcome::Issued)
        ->and(count($store->all()))->toBe(2);
});

it('binds different approval contexts to different requests', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);

    $manager->issue(reviewIssuanceEvaluation(new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1'])));
    $second = $manager->issue(reviewIssuanceEvaluation(new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-2'])));

    expect($second->outcome)->toBe(ReviewOutcome::Issued)
        ->and(count($store->all()))->toBe(2);
});

it('persists an empty approval context as [] and omits it from the fingerprint', function (): void {
    $store = new InMemoryReviewRequestStore;
    $stored = reviewIssuanceManager($store)->issue(
        reviewIssuanceEvaluation(new ActionContext(actor: 'customer:72')) // no approvalContext → []
    )->request;

    expect($stored?->approvalContext)->toBe([])
        // The fingerprint omits the approval_context key entirely when empty (never [] and never null).
        ->and($stored?->bindingFingerprint)->toBe(expectedReviewFingerprint(['order_id' => 1001], []));
});

it('refuses to issue a review for a capability with no application binding', function (): void {
    // Mirrors ApprovalManager::issue(): with no binder there is no argument-bound fingerprint to mint.
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);

    $transition = $manager->issue(reviewIssuanceEvaluation(new ActionContext(actor: 'customer:72'), withBinder: false));

    expect($transition->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($store->all())->toBe([]);
});

it('refuses to issue when the evaluation has no resolved capability', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);

    $evaluation = new Evaluation(
        ActionEnvelope::wrap(new ActionProposal('orders.cancel', ['order_id' => 1001], 'tool-call-1'), new ActionContext(actor: 'customer:72')),
        null, // no resolved capability
        ['order_id' => 1001],
        Decision::requireReview('A human must review this cancellation.'),
        EvaluationStage::Proposal,
    );

    expect($manager->issue($evaluation)->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($store->all())->toBe([]);
});

it('is fail-closed on the disposition — it never mints review state for a non-RequireReview decision', function (callable $makeDecision): void {
    // Only a RequireReview evaluation may mint a review request; every other disposition must not.
    $store = new InMemoryReviewRequestStore;
    $manager = reviewIssuanceManager($store);

    $transition = $manager->issue(reviewIssuanceEvaluation(
        new ActionContext(actor: 'customer:72'),
        decision: $makeDecision(),
    ));

    expect($transition->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($store->all())->toBe([]);
})->with([
    'permit' => [fn (): Decision => Decision::permit()],
    'deny' => [fn (): Decision => Decision::deny('Not permitted.')],
    'require confirmation' => [fn (): Decision => Decision::requireConfirmation('Confirm this action.')],
    'throttle' => [fn (): Decision => Decision::throttle('Too many.')],
]);

it('requires a default TTL of at least one second', function (): void {
    // Matches ApprovalManager / Capability::requiresConfirmation: a zero or negative default TTL would mint
    // an already-expired or malformed review request.
    expect(fn (): mixed => new ReviewManager(
        new InMemoryReviewRequestStore,
        new FrozenClock('2026-08-30 12:00:00'),
        new AllowAllReviewAuthorizer,
        0,
    ))->toThrow(InvalidArgumentException::class);
});
