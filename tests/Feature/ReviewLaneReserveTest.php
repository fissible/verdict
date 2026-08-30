<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\RequireReviewNotImplemented;
use Fissible\Verdict\VerdictManager;

// ADR 0035 §1 — the "loud-reserve". Until the asynchronous-review substrate lands, a RequireReview
// disposition must be a LOUD refusal (a thrown RequireReviewNotImplemented) at BOTH run() and
// runBound(), not the silent ExecutionResult::denied() it is today (src/VerdictManager.php:198,209).
// The path is shared, so it is proven by behavior at every externally visible site — proposal-stage
// run(), proposal-stage runBound(), and execution-stage runBound() — not by asserting DRY internals.
// The decision evidence must be recorded (by evaluate()/the execution record) BEFORE the throw.
// The reserve is a decision-time reaction, never a registration-time check. Every OTHER non-permit
// disposition (Deny, Throttle, …) must keep returning a denial; RequireConfirmation keeps its own
// admission path — none of them may be swept into the review throw.

final readonly class ReviewLaneTarget
{
    public function __construct(public int $id) {}
}

const REVIEW_REASON_PROPOSAL = 'A human must review this cancellation (proposal stage).';
const REVIEW_REASON_EXECUTION = 'A human must review this cancellation (execution stage).';

function reviewLaneEnvelope(string $toolCallId = 'tool-call-1'): ActionEnvelope
{
    return ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.cancel',
            arguments: ['order_id' => 7001],
            idempotencyKey: $toolCallId,
        ),
        context: new ActionContext(72, ['tenant_id' => 'store-1']),
    );
}

/**
 * A fully-bound capability whose executor flips $executed so a test can prove the reserve throws
 * BEFORE anything runs. Suitable for both run() (executor ignored) and runBound().
 */
function reviewLaneCapability(bool &$executed): Capability
{
    return Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): ReviewLaneTarget => new ReviewLaneTarget(
            (int) $envelope->proposal->arguments['order_id'],
        ),
    )->executionTarget(acceptTestSnapshot('review-lane-target-snapshot'))
        ->executeUsing(function (AuthorizedAction $action) use (&$executed): string {
            $executed = true;

            return 'cancelled';
        });
}

/** @return list<DecisionEvidence> */
function reviewLaneEvidence(string $stage): array
{
    $recorder = app(EvidenceWriter::class);
    assert($recorder instanceof InMemoryEvidenceRecorder);

    return array_values(array_filter(
        $recorder->all(),
        fn (DecisionEvidence $evidence): bool => $evidence->stage === $stage,
    ));
}

/** An authorizer that returns RequireReview at every stage, retaining a call count. */
function alwaysReviewAuthorizer(): CapabilityAuthorizer
{
    return new class implements CapabilityAuthorizer
    {
        public int $calls = 0;

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->calls++;

            return Decision::requireReview(REVIEW_REASON_PROPOSAL);
        }
    };
}

beforeEach(function (): void {
    // Default happy-path authorizer; individual tests override it.
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
});

it('run() throws a loud RequireReviewNotImplemented instead of a silent denial', function (): void {
    app()->instance(CapabilityAuthorizer::class, alwaysReviewAuthorizer());

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $ran = false;

    expect(fn (): mixed => $verdict->run(
        reviewLaneEnvelope(),
        function () use (&$ran): string {
            $ran = true;

            return 'done';
        },
    ))->toThrow(RequireReviewNotImplemented::class);

    // The unbound path never executes, and it recorded the proposal decision — with its real
    // reason — before throwing, so the refusal is not a trace-less drop.
    $proposal = reviewLaneEvidence('proposal');

    expect($ran)->toBeFalse()
        ->and($proposal)->toHaveCount(1)
        ->and($proposal[0]->disposition)->toBe('require_review')
        ->and($proposal[0]->reason)->toBe(REVIEW_REASON_PROPOSAL);
});

it('runBound() throws a loud RequireReviewNotImplemented on a proposal-stage RequireReview', function (): void {
    app()->instance(CapabilityAuthorizer::class, alwaysReviewAuthorizer());

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    expect(fn (): mixed => $verdict->runBound(reviewLaneEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);

    // The proposal decision evidence, with its reason, survived the throw.
    $proposal = reviewLaneEvidence('proposal');

    expect($executed)->toBeFalse()
        ->and($proposal)->toHaveCount(1)
        ->and($proposal[0]->disposition)->toBe('require_review')
        ->and($proposal[0]->reason)->toBe(REVIEW_REASON_PROPOSAL);
});

it('runBound() throws when RequireReview surfaces only at the execution stage', function (): void {
    // Proposal permits; the re-evaluation after target refresh returns RequireReview. Today that is
    // a second silent denial (VerdictManager.php:274). The reserve must be loud here too, or the
    // "no silent RequireReview" guarantee keeps a hole at exactly the gate the substrate targets.
    // refreshTarget() does not decide(), so decide() is called EXACTLY twice: proposal, execution.
    $auth = new class implements CapabilityAuthorizer
    {
        public int $calls = 0;

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            $this->calls++;

            return match ($this->calls) {
                1 => Decision::permit(),
                2 => Decision::requireReview(REVIEW_REASON_EXECUTION),
                // A third evaluation means the reserve threw from an unintended phase.
                default => throw new RuntimeException("Unexpected decide() call #{$this->calls}."),
            };
        }
    };
    app()->instance(CapabilityAuthorizer::class, $auth);

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    expect(fn (): mixed => $verdict->runBound(reviewLaneEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);

    // Exactly the proposal and execution evaluations ran; the execution-stage review decision,
    // with its own reason, is the one recorded before the throw.
    $execution = reviewLaneEvidence('execution');

    expect($executed)->toBeFalse()
        ->and($auth->calls)->toBe(2)
        ->and($execution)->toHaveCount(1)
        ->and($execution[0]->disposition)->toBe('require_review')
        ->and($execution[0]->reason)->toBe(REVIEW_REASON_EXECUTION);
});

it('makes the loud error actionable by naming the capability it refused', function (): void {
    // Loudness (ADR 0035 §1) is not merely "it throws" — a silent denial already "read as a human
    // deciding". The refusal must identify the capability so an operator/log reader can act on it,
    // matching the house exception style (CapabilityNotExecutable::named, UnsupportedApprovalDecision).
    app()->instance(CapabilityAuthorizer::class, alwaysReviewAuthorizer());

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    expect(fn (): mixed => $verdict->runBound(reviewLaneEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class, 'orders.cancel');
});

it('reacts at decision time, not registration time: registering a review capability is inert', function (): void {
    // ADR 0035 §1 is explicit that the reserve is NOT a registration-time check — dispositions are
    // produced dynamically by the authorizer. Registration must neither call the authorizer nor throw.
    $auth = alwaysReviewAuthorizer();
    app()->instance(CapabilityAuthorizer::class, $auth);

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    // Registration alone consulted no authorizer and raised nothing.
    expect($auth->calls)->toBe(0);

    // The throw arrives only when the action is evaluated.
    expect(fn (): mixed => $verdict->runBound(reviewLaneEnvelope()))
        ->toThrow(RequireReviewNotImplemented::class);

    expect($auth->calls)->toBeGreaterThanOrEqual(1);
});

it('leaves an ordinary Deny as a returned denial on runBound(), never a throw', function (): void {
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Not permitted.');
        }
    });

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $result = $verdict->runBound(reviewLaneEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition->value)->toBe('deny');
});

it('leaves an ordinary Deny as a returned denial on run(), never a throw', function (): void {
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Not permitted.');
        }
    });

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $ran = false;
    $result = $verdict->run(reviewLaneEnvelope(), function () use (&$ran): string {
        $ran = true;

        return 'done';
    });

    expect($result->executed)->toBeFalse()
        ->and($ran)->toBeFalse()
        ->and($result->evaluation->decision->disposition->value)->toBe('deny');
});

it('leaves a Throttle as a returned denial, never a throw — only RequireReview is loud', function (): void {
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::throttle('Too many cancellations.');
        }
    });

    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $result = $verdict->runBound(reviewLaneEnvelope());

    expect($result->executed)->toBeFalse()
        ->and($executed)->toBeFalse()
        ->and($result->evaluation->decision->disposition->value)->toBe('throttle');
});

it('does not sweep RequireConfirmation into the review throw — it keeps its own admission path', function (): void {
    // runBound() admits [Permit, RequireConfirmation]. The reserve must single out RequireReview:
    // a confirmation action is admitted and denied through the approval gate (here, with no approved
    // tool-call context established, the approval validation refuses before it executes), but must
    // never throw the review error. The point under test is that the reserve does not intercept it.
    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(
        reviewLaneCapability($executed)
            ->requiresConfirmation(bindUsing: fn (): array => ['order_id' => 7001], reason: 'Confirm the cancellation.'),
    );

    expect(fn (): mixed => $verdict->runBound(reviewLaneEnvelope()))
        ->not->toThrow(RequireReviewNotImplemented::class);

    expect($executed)->toBeFalse();
});

it('still executes a permitted runBound() action — the reserve does not touch the happy path', function (): void {
    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $result = $verdict->runBound(reviewLaneEnvelope());

    expect($result->executed)->toBeTrue()
        ->and($executed)->toBeTrue();
});

it('still executes a permitted run() action — the reserve does not touch the happy path', function (): void {
    $executed = false;
    $verdict = app(VerdictManager::class);
    $verdict->capability(reviewLaneCapability($executed));

    $ran = false;
    $result = $verdict->run(reviewLaneEnvelope(), function () use (&$ran): string {
        $ran = true;

        return 'done';
    });

    expect($result->executed)->toBeTrue()
        ->and($ran)->toBeTrue();
});
