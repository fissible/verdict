<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ResourceProjection;

// ADR 0038 §1 — a capability may register a binding-informed presentation closure for the approver:
//   describeForApprover(fn (ActionEnvelope $envelope, mixed $target, array $binding): string).
// $binding is the already-computed approvalBinding($envelope, $target). Verdict never interprets arguments;
// the app renders. The closure is carried through EVERY with-style builder method (Capability is immutable),
// and invoked via approverDescription(); absent → null. This slice is the leaf value layer only — materialisation,
// release states, and evidence come later. #300's issuedAt on ApprovalChallenge rides here too.

function describedCapability(?array &$sawBinding = null): Capability
{
    return Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $target): array => ['bound_order' => $target['order_id']], reason: 'Confirm.')
        ->executionTarget(acceptTestSnapshot('describe-target'))
        ->describeForApprover(function (ActionEnvelope $envelope, mixed $target, array $binding) use (&$sawBinding): string {
            $sawBinding = $binding; // capture what the closure was handed

            return "Cancel order #{$envelope->proposal->arguments['order_id']}";
        });
}

function describeEnvelope(int $orderId = 9001): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('orders.cancel', ['order_id' => $orderId], 'tool-call-1'),
        new ActionContext(actor: 'customer:72', approvalContext: ['tenant_id' => 'store-1']),
    );
}

// ── the closure is set, invoked, and receives the already-computed binding ────────────────────────

it('renders an approver description from the closure, passing through the binding it is handed', function (): void {
    $sawBinding = null;
    $capability = describedCapability($sawBinding);
    $envelope = describeEnvelope(9001);
    // A distinct sentinel binding — NOT what approvalBinding() would compute. approverDescription() must relay it
    // to the closure verbatim: the leaf only passes through. (Computing the binding once and feeding it here is
    // the materialisation slice's job, pinned there.)
    $sentinel = ['bound_order' => 9001, 'sentinel' => 'passed-through'];

    $description = $capability->approverDescription($envelope, ['order_id' => 9001], $sentinel);

    expect($description)->toBe('Cancel order #9001')
        ->and($sawBinding)->toBe($sentinel);
});

it('returns null when no describer is registered', function (): void {
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executionTarget(acceptTestSnapshot('describe-target'));
    $envelope = describeEnvelope();

    expect($capability->approverDescription($envelope, ['order_id' => 9001], []))->toBeNull();
});

// ── the describer survives EVERY with-style builder method (Capability is immutable) ──────────────

it('carries the describer through every builder method, whichever order it is registered', function (): void {
    $envelope = describeEnvelope(7);
    $target = ['order_id' => 7];

    // Registered FIRST, then every other builder method is chained after it.
    $first = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => 'described')
        ->executeUsing(fn (): string => 'ok')
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'r')
        ->rateLimit(RateLimitPolicy::fixedWindow(name: 'l', limit: 1, windowSeconds: 60, keyUsing: fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->atMostOnce(ExecutionClaimPolicy::named('claim', fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->executionTarget(acceptTestSnapshot('describe-target'))
        ->configurationVersion('v1')
        ->requiresIntentRecord(true)
        ->resourceProjection(ResourceProjection::declared('rec/v1', fn (ActionEnvelope $e, mixed $t): array => ['r' => 1]));

    // Registered LAST, after every other builder method.
    $last = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executeUsing(fn (): string => 'ok')
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'r')
        ->rateLimit(RateLimitPolicy::fixedWindow(name: 'l', limit: 1, windowSeconds: 60, keyUsing: fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->atMostOnce(ExecutionClaimPolicy::named('claim', fn (ActionEnvelope $e, mixed $t): array => ['k' => 1]))
        ->executionTarget(acceptTestSnapshot('describe-target'))
        ->configurationVersion('v1')
        ->requiresIntentRecord(true)
        ->resourceProjection(ResourceProjection::declared('rec/v1', fn (ActionEnvelope $e, mixed $t): array => ['r' => 1]))
        ->describeForApprover(fn (ActionEnvelope $e, mixed $t, array $b): string => 'described');

    expect($first->approverDescription($envelope, $target, []))->toBe('described')
        ->and($last->approverDescription($envelope, $target, []))->toBe('described')
        // …and describeForApprover(), registered LAST, preserved every prior configuration it cloned over.
        ->and($last->confirmationRequired())->toBeTrue()
        ->and($last->rateLimitPolicy())->not->toBeNull()
        ->and($last->executionClaimPolicy())->not->toBeNull()
        ->and($last->executionTargetPolicy())->not->toBeNull()
        ->and($last->intentRecordRequirement())->toBeTrue()
        ->and($last->declaredResourceProjection())->not->toBeNull();
});

// ── the release state is a typed value, not a bare nullable (ADR 0038 §3) ─────────────────────────

it('defines the typed release states', function (): void {
    expect(ApproverSummaryRelease::cases())
        ->toBe([ApproverSummaryRelease::Released, ApproverSummaryRelease::NotReleased, ApproverSummaryRelease::ReleaseDenied]);
});

// ── #300: the challenge carries the immutable issuance instant ─────────────────────────────────────

it('surfaces the receipt issuance instant as issuedAt on the challenge', function (): void {
    $receipt = new ApprovalReceipt(
        id: 'rcpt-1',
        toolCallId: 'tool-call-1',
        capability: 'orders.cancel',
        bindingFingerprint: str_repeat('a', 64),
        provenance: null,
        approvalContext: null,
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2026-08-31 12:15:00'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-31 12:00:00'),
        updatedAt: new DateTimeImmutable('2026-08-31 12:00:00'),
    );

    $challenge = ApprovalChallenge::fromReceipt($receipt);

    // issuedAt is the issuance instant (createdAt), distinct from expiry — a consumer computes elapsed time.
    expect($challenge->issuedAt)->toEqual(new DateTimeImmutable('2026-08-31 12:00:00'))
        ->and($challenge->issuedAt)->not->toEqual($challenge->expiresAt);
});
