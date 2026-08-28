<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Testing\CapabilitySecurityAssertionFailed;
use Fissible\Verdict\Testing\CapabilitySecurityTestKit;
use Fissible\Verdict\Tests\Support\LosableApprovalReceiptStore;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Container\Container;

/**
 * The confound #343 is really about, after review corrected its premise.
 *
 * `assertApprovalBindingInvalidation()` proves an approval cannot survive a change to the binding it
 * was granted against, and its evidence is `approval_outcome = not_found` on the re-run. But the
 * receipt lookup is keyed on the tool-call id AND the recomputed binding fingerprint, so `not_found`
 * is equally what a *vanished receipt* produces. An invalidation callback that loses the receipt —
 * by clearing a cache, truncating a table, or rolling back a transaction — makes the scenario pass
 * while demonstrating nothing about binding invalidation.
 *
 * The issue's original diagnosis, that a missing approval FRAME could produce the same pass, does
 * not hold: `ApprovalManager::executionStateFailure()` checks `ApprovalExecutionContext::allows()`
 * before any receipt lookup and returns `invalid_state`, which the kit's `not_found` assertion
 * already rejects. That route is discriminated by design and needs no new probe.
 *
 * The fix is a continuity assertion: after the invalidation callback runs and before the re-run,
 * the kit establishes that the approved receipt is still there. Then `not_found` can only mean the
 * binding stopped matching, which is the proposition the scenario claims to demonstrate.
 */
/**
 * File-local rather than shared: these mirror the helpers in CapabilitySecurityTestKitTest, and
 * duplicating two small builders is cheaper than making that file's private fixtures global.
 */
function receiptContinuityEnvelope(string $capability, string $key, int $recordId = 1001): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, ['record_id' => $recordId], $key),
        new ActionContext(actor: 72, metadata: ['tenant_id' => 'tenant-a']),
    );
}

function permitReceiptContinuityAuthorization(Container $app): void
{
    $app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

/**
 * Assert the instrument before relying on it. A control built from the code under test can pass for
 * the wrong reason, so this pins that the store really does lose receipts on demand — otherwise the
 * RED test below could go green because nothing was ever lost.
 */
it('loses a receipt only once it is told to', function (): void {
    $store = new LosableApprovalReceiptStore;
    $this->app->instance(ApprovalReceiptStore::class, $store);
    permitReceiptContinuityAuthorization($this->app);

    $target = (object) ['id' => 1001, 'version' => 1];
    $capability = Capability::usingPolicy('records.continuity-probe', 'update', fn (): object => $target)
        ->requiresConfirmation(
            fn (ActionEnvelope $envelope, object $record): array => ['record_id' => $record->id, 'version' => $record->version],
        )
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(function (): void {});

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    $envelope = receiptContinuityEnvelope('records.continuity-probe', 'continuity-1');
    $verdict->requestConfirmation($envelope);

    expect($verdict->approvals()->challengeForToolCall('continuity-1'))->not->toBeNull();

    $store->lose();

    expect($verdict->approvals()->challengeForToolCall('continuity-1'))->toBeNull();
});

it('refuses to read a vanished receipt as an invalidated binding', function (): void {
    $store = new LosableApprovalReceiptStore;
    $this->app->instance(ApprovalReceiptStore::class, $store);
    permitReceiptContinuityAuthorization($this->app);

    $target = (object) ['id' => 1001, 'version' => 1];
    $executions = 0;
    $capability = Capability::usingPolicy('records.continuity', 'update', fn (): object => $target)
        ->requiresConfirmation(
            fn (ActionEnvelope $envelope, object $record): array => ['record_id' => $record->id, 'version' => $record->version],
        )
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    // The binding is never touched. The receipt simply disappears — the shape of a cleared cache, a
    // truncated table, or a rolled-back transaction inside an adopter's invalidation callback.
    expect(fn () => CapabilitySecurityTestKit::for($verdict, 'records.continuity')->assertApprovalBindingInvalidation(
        receiptContinuityEnvelope('records.continuity', 'continuity-2'),
        'operator:72',
        function () use ($store): void {
            $store->lose();
        },
        fn (): bool => $executions === 0,
    ))->toThrow(CapabilitySecurityAssertionFailed::class, 'approval-binding-receipt-retained');

    // The re-run must not have executed either; a lost receipt is still a denial, just not the one
    // the scenario claims to have demonstrated.
    expect($executions)->toBe(0);
});

it('still demonstrates an invalidated binding when the receipt is retained', function (): void {
    $store = new LosableApprovalReceiptStore;
    $this->app->instance(ApprovalReceiptStore::class, $store);
    permitReceiptContinuityAuthorization($this->app);

    $target = (object) ['id' => 1001, 'version' => 1];
    $executions = 0;
    $capability = Capability::usingPolicy('records.retained', 'update', fn (): object => $target)
        ->requiresConfirmation(
            fn (ActionEnvelope $envelope, object $record): array => ['record_id' => $record->id, 'version' => $record->version],
        )
        ->executionTarget(acceptTestSnapshot())
        ->executeUsing(function () use (&$executions): void {
            $executions++;
        });

    $verdict = app(VerdictManager::class);
    $verdict->capability($capability);

    // The genuine article: the receipt stays, the binding moves. Two things have to hold, and the
    // second is what makes this a control rather than decoration — asserting only that the scenario
    // still passes would pass equally against a kit that never checks continuity at all.
    $operationsAtInvalidation = null;

    CapabilitySecurityTestKit::for($verdict, 'records.retained')->assertApprovalBindingInvalidation(
        receiptContinuityEnvelope('records.retained', 'retained-1'),
        'operator:72',
        function () use ($target, $store, &$operationsAtInvalidation): void {
            $target->version = 2;
            $operationsAtInvalidation = count($store->operations());
        },
        fn (): bool => $executions === 0,
    );

    // The continuity assertion must not fire on a correct scenario...
    expect($executions)->toBe(0);

    // ...and it must have read THE APPROVED RECEIPT, by its own id, seen it still approved, and done
    // so before the re-run. A read count alone is satisfied by a read of anything, at any point —
    // including after runBound(), where it would establish nothing about the denial that preceded it.
    $operations = $store->operations();
    $approved = array_values(array_filter(
        $operations,
        static fn (string $operation): bool => str_starts_with($operation, 'approve:'),
    ));

    expect($approved)->not->toBeEmpty('The scenario never approved a receipt, so there is nothing for continuity to be about.');

    $receiptId = substr($approved[0], strlen('approve:'));
    $after = array_slice($operations, $operationsAtInvalidation);

    $continuityRead = null;

    foreach ($after as $position => $operation) {
        // The re-run's validate() is the boundary: a continuity read has to land before it.
        if (str_starts_with($operation, 'validate:')) {
            break;
        }

        if ($operation === 'find:'.$receiptId.':approved') {
            $continuityRead = $position;
        }
    }

    expect($continuityRead)->not->toBeNull(
        'The kit did not read the approved receipt by its own id, and see it still approved, between the invalidation callback and the re-run. '
        .'Without that, not_found on the re-run is equally explained by the receipt having vanished. Operations after the callback were: '
        .implode(', ', $after)
    );
});
