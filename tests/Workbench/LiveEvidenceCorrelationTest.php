<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictManager;
use Workbench\App\Storefront\Customer;
use Workbench\App\Storefront\StorefrontLiveSuiteFactory;

/**
 * #183. The guarded live arm reads decision evidence back through the workbench's
 * `InMemoryLiveEvidenceReader`. That read must find what `VerdictManager` wrote, and it must not
 * find what a *previous* trial wrote.
 *
 * No model is involved. The defect is a container-lifetime mismatch, so a live provider adds
 * nothing but flakiness — the write path, the reset, and the read path are all that matter.
 */
function writeOneDecision(string $orderId = '1002'): string
{
    $verdict = app(VerdictManager::class);

    $result = $verdict->runBound(ActionEnvelope::wrap(
        proposal: new ActionProposal(
            capability: 'orders.view',
            arguments: ['order_id' => $orderId],
            idempotencyKey: 'tool-call-'.$orderId,
        ),
        context: new ActionContext(new Customer(72, 'Ada')),
    ));

    return $result->evaluation->decision->disposition->value;
}

it('resolves the same recorder instance on the write and read paths', function (): void {
    // The write path: VerdictManager takes an EvidenceWriter, which with no configured writer
    // resolves the EvidenceRecorder. The read path: the workbench reader reads the recorder
    // directly. If these are different objects, every correlation lookup returns nothing.
    $writerSide = app(EvidenceWriter::class);
    $readerSide = app(InMemoryEvidenceRecorder::class);

    expect($writerSide)->toBeInstanceOf(InMemoryEvidenceRecorder::class)
        ->and(spl_object_id($writerSide))->toBe(spl_object_id($readerSide));
});

it('keeps the write and read paths on the same recorder across a trial reset', function (): void {
    // Establish the binding, then reset the scope the way StorefrontLiveSuiteFactory::makeForTrial()
    // does. A singleton writer pins the pre-reset recorder and diverges from here on.
    app(EvidenceWriter::class);
    app(InMemoryEvidenceRecorder::class);

    $this->app->forgetScopedInstances();

    expect(spl_object_id(app(EvidenceWriter::class)))
        ->toBe(spl_object_id(app(InMemoryEvidenceRecorder::class)));
});

it('lets the live reader find the decision evidence VerdictManager just wrote', function (): void {
    $factory = app(StorefrontLiveSuiteFactory::class);

    // makeForTrial() resets the scope and builds this trial's suite, exactly as a live run does.
    $factory->makeForTrial(0);

    writeOneDecision();

    $recorder = app(InMemoryEvidenceRecorder::class);

    // The reader's correlation lookup is over what this recorder holds. Empty here is precisely
    // the LiveObservationUnavailable the guarded arm reports on every reachable case.
    expect($recorder->all())->not->toBeEmpty();
});

it('does not let a previous trial\'s evidence reach the next trial\'s reader', function (): void {
    $factory = app(StorefrontLiveSuiteFactory::class);

    $factory->makeForTrial(0);
    writeOneDecision();
    expect(app(InMemoryEvidenceRecorder::class)->all())->not->toBeEmpty();

    // ADR 0020: a trial must not observe what the trial before it created. Fixing correlation by
    // making the recorder process-stable would satisfy the test above and break this one.
    $factory->makeForTrial(1);

    expect(app(InMemoryEvidenceRecorder::class)->all())->toBeEmpty();
});

it('keeps the EvidenceRecorder contract resolving to the same instance as the concrete recorder', function (): void {
    // The workbench aliases the contract to the concrete class. If the package's singleton binding
    // wins for the contract while the workbench's scoped binding serves the concrete class, the
    // write and read paths split without either binding looking wrong in isolation.
    expect(spl_object_id(app(EvidenceRecorder::class)))
        ->toBe(spl_object_id(app(InMemoryEvidenceRecorder::class)));
});

it('re-resolves every recorder-dependent binding after a trial reset', function (): void {
    // EvidenceWriter is not the only binding whose value depends on a rebindable recorder.
    // ProvenanceLedgerStore resolves the recorder directly; CapabilityConfigurationStore resolves a
    // store class the application may bind scoped. The shared property is that none may survive a
    // reset holding a pre-reset dependency, so each is asserted rather than assumed from the one
    // that was diagnosed.
    $bindings = [
        EvidenceWriter::class,
        ProvenanceLedgerStore::class,
        CapabilityConfigurationStore::class,
    ];

    // Objects, not spl_object_ids: PHP recycles ids once an object is freed, so an id comparison
    // here would be answering a question about the garbage collector.
    $before = [];

    foreach ($bindings as $binding) {
        $before[$binding] = app($binding);
    }

    $this->app->forgetScopedInstances();

    foreach ($bindings as $binding) {
        expect(app($binding))
            ->not->toBe($before[$binding], "{$binding} survived a reset holding pre-reset state");
    }
});
