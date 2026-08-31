<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Reviews\InMemoryReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\Verdict\Tests\Support\FrozenClock;

// ADR 0035 §5 — the execution-gate PRIMITIVES on ReviewManager: validate(Evaluation) and consume(Evaluation),
// the calls the runBound() gate (6b-2) uses to re-authorize a reviewed action. They mirror ApprovalManager's:
// derive the SAME binding fingerprint issue() derives — capability, execution-target policy, arguments,
// application binding, and approval_context — and delegate to the store. So an action ISSUED under a binding
// round-trips (issue → approve → validate/consume) and a DIFFERENT binding on ANY component does not match.
// validate is observational; consume is single-use. An unbindable (or absent) capability → InvalidState.
// The evaluations are execution-stage, as the gate supplies; per ADR 0035 §5.2 the primitives also guard the
// disposition — a fresh evaluation whose policy is no longer RequireReview is refused with InvalidState.

function gateEvaluation(
    array $arguments = ['order_id' => 7001],
    bool $withBinder = true,
    ?array $bindingOverride = null,
    array $context = ['tenant_id' => 'store-1'],
    string $targetPolicy = 'gate-target',
    string $capabilityName = 'orders.cancel',
    ?Decision $decision = null,
): Evaluation {
    $capability = Capability::usingPolicy(
        $capabilityName,
        'cancel',
        fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments,
    )->executionTarget(acceptTestSnapshot($targetPolicy));

    if ($withBinder) {
        $capability = $capability->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, array $target): array => $bindingOverride ?? ['bound_order' => $target['order_id']],
            reason: 'Confirm.',
        );
    }

    $envelope = ActionEnvelope::wrap(
        new ActionProposal($capabilityName, $arguments, 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: $context),
    );

    return new Evaluation($envelope, $capability, $arguments, $decision ?? Decision::requireReview('Needs a human.'), EvaluationStage::Execution);
}

function gateManager(InMemoryReviewRequestStore $store, string $clock = '2026-08-30 12:00:00'): ReviewManager
{
    return new ReviewManager($store, new FrozenClock($clock), new AllowAllReviewAuthorizer, 900);
}

/** Issue the given evaluation and approve it in the store, returning the request id. */
function gateApprovedRequestId(InMemoryReviewRequestStore $store, ReviewManager $manager, Evaluation $evaluation): string
{
    $id = $manager->issue($evaluation)->request->id;
    $store->approve($id, 'reviewer-7', new DateTimeImmutable('2026-08-30 12:05:00'));

    return $id;
}

// ── validate/consume round-trip on the issuance binding ──────────────────────────────────────────

it('validates an approved request issued under the same binding, without mutating any field', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    $id = gateApprovedRequestId($store, $manager, gateEvaluation());

    $before = $store->find($id);
    $transition = $manager->validate(gateEvaluation());
    $after = $store->find($id);

    expect($transition->outcome)->toBe(ReviewOutcome::Approved)
        ->and($transition->request?->id)->toBe($id)
        ->and($after)->toEqual($before); // the WHOLE stored record is untouched, not merely its status
});

it('consumes an approved request once under the same binding, then refuses', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    $id = gateApprovedRequestId($store, $manager, gateEvaluation());

    expect($manager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::Consumed)
        ->and($store->find($id)?->status)->toBe(ReviewStatus::Consumed)
        ->and($store->find($id)?->consumedAt)->toEqual(new DateTimeImmutable('2026-08-30 12:00:00'))
        ->and($manager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::InvalidState);
});

// ── every fingerprint component participates in matching ─────────────────────────────────────────

it('does not match a request across a different approval context', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    gateApprovedRequestId($store, $manager, gateEvaluation(context: ['tenant_id' => 'store-1']));

    // Same action, a different tenant: an implementation that dropped approval_context would match store-1's.
    expect($manager->validate(gateEvaluation(context: ['tenant_id' => 'store-2']))->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation(context: ['tenant_id' => 'store-2']))->outcome)->toBe(ReviewOutcome::NotFound);
});

it('does not match a request across a different execution-target policy', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    gateApprovedRequestId($store, $manager, gateEvaluation(targetPolicy: 'policy-a'));

    expect($manager->validate(gateEvaluation(targetPolicy: 'policy-b'))->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation(targetPolicy: 'policy-b'))->outcome)->toBe(ReviewOutcome::NotFound);
});

it('does not match when a non-binding argument differs (arguments participate independently of the binding)', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    // Constant binding, so only the top-level `arguments` component distinguishes these two.
    gateApprovedRequestId($store, $manager, gateEvaluation(arguments: ['order_id' => 7001, 'note' => 'a'], bindingOverride: ['fixed' => 1]));

    expect($manager->validate(gateEvaluation(arguments: ['order_id' => 7001, 'note' => 'b'], bindingOverride: ['fixed' => 1]))->outcome)
        ->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation(arguments: ['order_id' => 7001, 'note' => 'b'], bindingOverride: ['fixed' => 1]))->outcome)
        ->toBe(ReviewOutcome::NotFound);
});

it('does not match when the application binding differs at identical arguments', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    // Same arguments/context/policy, different binder OUTPUT.
    gateApprovedRequestId($store, $manager, gateEvaluation(bindingOverride: ['seat' => 'A1']));

    expect($manager->validate(gateEvaluation(bindingOverride: ['seat' => 'B2']))->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation(bindingOverride: ['seat' => 'B2']))->outcome)->toBe(ReviewOutcome::NotFound);
});

it('does not match a request across a different capability', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    gateApprovedRequestId($store, $manager, gateEvaluation(capabilityName: 'orders.cancel'));

    // Same target/arguments/context/binding, a different capability — the store keys on (capability, binding).
    expect($manager->validate(gateEvaluation(capabilityName: 'orders.refund'))->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation(capabilityName: 'orders.refund'))->outcome)->toBe(ReviewOutcome::NotFound);
});

// ── refusals ─────────────────────────────────────────────────────────────────────────────────────

it('refuses to validate or consume a rejected request', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    $id = $manager->issue(gateEvaluation())->request->id;
    $store->reject($id, 'reviewer-9', new DateTimeImmutable('2026-08-30 12:05:00'));

    expect($manager->validate(gateEvaluation())->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($manager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::InvalidState);
});

it('refuses to validate or consume when the fresh policy no longer requires review', function (Decision $fresh): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    $id = gateApprovedRequestId($store, $manager, gateEvaluation()); // issued + approved under RequireReview

    // ADR 0035 §5.2 — the gate re-evaluates at execution and the action must STILL require review. A same-bound
    // evaluation whose current policy is anything OTHER than RequireReview must not be admitted by a stale
    // approval — the guard is disposition !== RequireReview, not merely "is Permit". Each fresh disposition here
    // reuses the identical binding, so the fingerprint MATCHES; only the §5.2 disposition guard can refuse it.
    $freshEval = gateEvaluation(decision: $fresh);

    expect($manager->validate($freshEval)->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($manager->consume($freshEval)->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($store->find($id)?->status)->toBe(ReviewStatus::Approved); // consume left the request untouched
})->with([
    'permit' => fn () => Decision::permit('Now allowed outright.'),
    'deny' => fn () => Decision::deny('Now forbidden outright.'),
    'requireConfirmation' => fn () => Decision::requireConfirmation('Now only a synchronous confirm.'),
]);

it('reports NotFound validating or consuming a binding with no request', function (): void {
    $manager = gateManager(new InMemoryReviewRequestStore);

    expect($manager->validate(gateEvaluation())->outcome)->toBe(ReviewOutcome::NotFound)
        ->and($manager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::NotFound);
});

it('refuses to validate or consume a request that is only pending', function (): void {
    $store = new InMemoryReviewRequestStore;
    $manager = gateManager($store);
    $manager->issue(gateEvaluation()); // pending, never approved

    expect($manager->validate(gateEvaluation())->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($manager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::InvalidState);
});

it('reports Expired validating or consuming an approved-but-lapsed request', function (): void {
    $store = new InMemoryReviewRequestStore;
    gateApprovedRequestId($store, gateManager($store), gateEvaluation()); // expiresAt 12:15
    $lateManager = gateManager($store, clock: '2026-08-30 14:00:00'); // past the expiry

    expect($lateManager->validate(gateEvaluation())->outcome)->toBe(ReviewOutcome::Expired)
        ->and($lateManager->consume(gateEvaluation())->outcome)->toBe(ReviewOutcome::Expired);
});

it('refuses to validate or consume a capability with no application binding', function (): void {
    $manager = gateManager(new InMemoryReviewRequestStore);

    expect($manager->validate(gateEvaluation(withBinder: false))->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($manager->consume(gateEvaluation(withBinder: false))->outcome)->toBe(ReviewOutcome::InvalidState);
});

it('refuses to validate or consume when the evaluation has no resolved capability', function (): void {
    $manager = gateManager(new InMemoryReviewRequestStore);
    $evaluation = new Evaluation(
        ActionEnvelope::wrap(new ActionProposal('orders.cancel', ['order_id' => 7001], 'tool-call-1'), new ActionContext(actor: 'customer:72')),
        null, // no resolved capability
        ['order_id' => 7001],
        Decision::requireReview('Needs a human.'),
        EvaluationStage::Execution,
    );

    expect($manager->validate($evaluation)->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and($manager->consume($evaluation)->outcome)->toBe(ReviewOutcome::InvalidState);
});
