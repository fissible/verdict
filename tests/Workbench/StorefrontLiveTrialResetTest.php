<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Workbench\App\Storefront\ActionLog;
use Workbench\App\Storefront\StorefrontLiveSuiteFactory;

/**
 * #137 / ADR 0020. The workbench is the worked example of an application-owned trial reset, so its
 * reset is worth proving rather than assuming — no model is needed to check that the state a trial
 * would leave behind is gone.
 */
it('declares the trial contract', function (): void {
    expect(app(StorefrontLiveSuiteFactory::class))->toBeInstanceOf(LiveEvaluationTrialFactory::class);
});

it('discards the side effects and evidence a previous trial recorded', function (): void {
    $factory = app(StorefrontLiveSuiteFactory::class);

    // Stand in for what a trial leaves behind, without invoking a model.
    app(ActionLog::class)->record('orders.cancel', 1002);
    app(EvidenceRecorder::class);
    // Hold the object, not its spl_object_id: PHP recycles ids once an object is freed, so an
    // id-only comparison can pass because the reset leaked a reference and fail once it stops.
    // That is exactly what #183 changed — before it, a pinned EvidenceWriter kept the pre-reset
    // recorder alive and gave the replacement a different id by accident.
    $recorderBefore = app(InMemoryEvidenceRecorder::class);

    expect(app(ActionLog::class)->all())->toHaveCount(1);

    $factory->makeForTrial(1);

    expect(app(ActionLog::class)->all())->toBe([])
        ->and(app(InMemoryEvidenceRecorder::class))->not->toBe($recorderBefore);
});

it('gives each trial fresh security-state stores', function (): void {
    $factory = app(StorefrontLiveSuiteFactory::class);

    $before = [
        spl_object_id(app(ExecutionClaimStore::class)),
        spl_object_id(app(ApprovalReceiptStore::class)),
    ];

    $factory->makeForTrial(1);

    $after = [
        spl_object_id(app(ExecutionClaimStore::class)),
        spl_object_id(app(ApprovalReceiptStore::class)),
    ];

    // An execution claim admitted in one trial cannot block the identical binding in the next,
    // and an approval receipt cannot carry across, because neither store survives the reset.
    expect($after[0])->not->toBe($before[0])
        ->and($after[1])->not->toBe($before[1]);
});

it('keeps capability registrations across a reset, because a reset discards state and not configuration', function (): void {
    $factory = app(StorefrontLiveSuiteFactory::class);

    $suite = $factory->makeForTrial(0);
    $afterReset = $factory->makeForTrial(1);

    // Building a suite resolves the storefront capabilities; if the reset had cleared the singleton
    // CapabilityRegistry, the second build would raise rather than produce the same case set.
    expect($afterReset->name)->toBe($suite->name)
        ->and(array_map(fn ($case): string => $case->id, $afterReset->cases))
        ->toBe(array_map(fn ($case): string => $case->id, $suite->cases));
});
