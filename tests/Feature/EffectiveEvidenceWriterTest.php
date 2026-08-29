<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Illuminate\Support\Facades\Artisan;

/**
 * #322 — `verdict.evidence.writer` overrides where evidence writes go regardless of `recorder`, but
 * every durability judgment resolved from `verdict.evidence.recorder` alone. These are the repo's
 * first tests that set `verdict.evidence.writer`; each asserts the judgment follows the *effective*
 * evidence class (the writer when set, else the recorder).
 */
const FAKE_ATTEST_VERIFY_322 = 'attest:verify {--chain=} {--from=} {--to=} {--trusted-key=*} {--trusted-key-file=*} {--min-anchor=} {--allow-provider-disagreement} {--allow-untrusted} {--bitcoin-core-rpc=} {--bitcoin-core-cookie=} {--esplora-url=} {--json}';

/** A durable-*writing* evidence writer that declares no DurableEvidenceRecorder marker (#310 shape). */
final class VolatileCustomEvidenceRecorder implements EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}
}

// Site 1 — capability-configuration store selection.
it('selects the durable configuration store when a durable writer overrides a null recorder (#322)', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class); // durable + a valid EvidenceWriter
    config()->set('verdict.capability_configurations.store', null);

    app()->forgetScopedInstances();
    app()->forgetInstance(CapabilityConfigurationStore::class);

    expect(app(CapabilityConfigurationStore::class))->toBeInstanceOf(DatabaseCapabilityConfigurationStore::class);
});

// Site 2 — verdict:validate durability warnings.
it('does not warn about a no-op recorder or store when a durable writer override is set (#322)', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('no-op evidence recorder')
        ->doesntExpectOutputToContain('no-op configuration store');
});

it('still warns about a no-op recorder when neither recorder nor writer is durable (#322 control)', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', null);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no-op evidence recorder');
});

it('treats an empty writer config as no override, warning on the null recorder (#322 edge)', function (): void {
    // An empty-string writer is not an override; the effective class must fall through to the
    // recorder, not become the empty string. Guards the resolver against `?? ''` mishandling.
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', '');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no-op evidence recorder');
});

it('warns about the no-op configuration store when the writer writes but declares no durable marker (#322)', function (): void {
    // A writer override makes evidence durable — so no no-op-recorder warning — but this writer does
    // not implement DurableEvidenceRecorder, so the capability-config store falls through to no-op
    // and the #310 mismatch warning must fire, keyed on the effective writer, not the null recorder.
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', VolatileCustomEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', null);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('no-op evidence recorder')
        ->expectsOutputToContain('no-op configuration store');
});

// Site 3 — verdict:evidence:verify under an Attest-class writer override.
it('verifies evidence when an Attest-class writer overrides the recorder (#322)', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict');

    Artisan::command(FAKE_ATTEST_VERIFY_322, fn (): int => 0);

    $this->artisan('verdict:evidence:verify')
        ->doesntExpectOutputToContain('requires verdict.evidence.recorder to be AttestEvidenceRecorder')
        ->assertExitCode(0);
});
