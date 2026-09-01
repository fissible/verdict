<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Support\ApproverSummary;

/** A stand-in durable writer that IS attest-capable — the presence of AttestsIssuance is what validate checks. */
final class AttestCapableProbeWriter implements AttestsIssuance, EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void {}
}

// ADR 0038 §5 — a strict capability with no configured Attest backend is a DEPLOYMENT error: every issuance would
// fail closed at runtime, so `verdict:validate` must surface it up front (an error, exit 1) rather than leaving the
// operator to discover it one refused action at a time. The default recorder (NullEvidenceRecorder) cannot attest.

function strictValidateCapability(bool $strict): Capability
{
    $capability = Capability::usingPolicy('orders.cancel', 'cancel', fn (ActionEnvelope $e): array => $e->proposal->arguments)
        ->executeUsing(fn (): string => 'ok')
        ->requiresConfirmation(bindUsing: fn (ActionEnvelope $e, array $t): array => ['b' => 1], reason: 'Confirm.')
        ->executionTarget(acceptTestSnapshot('validate-strict-target'));

    return $strict ? $capability->requiresAttestedIssuance() : $capability;
}

it('reports a strict capability with no attest backend as a deployment error', function (): void {
    app(CapabilityRegistry::class)->register(strictValidateCapability(strict: true));

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Capability [orders.cancel] requires attested issuance but no Attest backend is configured')
        ->assertExitCode(1);
});

it('does not report an attest error for a non-strict capability', function (): void {
    app(CapabilityRegistry::class)->register(strictValidateCapability(strict: false));

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('requires attested issuance')
        ->assertExitCode(0);
});

it('does not report an attest error for a strict capability when an attest backend is configured', function (): void {
    // With an attest-capable writer bound, the deployment gap is closed — validate must NOT flag the capability.
    // (Guards against an implementation that rejects every strict capability regardless of backend.)
    app()->instance(EvidenceWriter::class, new AttestCapableProbeWriter);
    app(CapabilityRegistry::class)->register(strictValidateCapability(strict: true));

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('requires attested issuance')
        ->assertExitCode(0);
});
