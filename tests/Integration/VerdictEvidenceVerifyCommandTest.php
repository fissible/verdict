<?php

declare(strict_types=1);

use Fissible\Attest\Signing\KeyPair;
use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Tests\Support\StaticAttestChainResolver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict');

    $seed = base64_decode((string) getenv('ATTEST_SIGNING_KEY_SEED'), true);
    assert(is_string($seed));

    $keyPair = KeyPair::fromSeed($seed);
    config()->set('attest.verification.trusted_keys', [
        'verdict-test='.base64_encode($keyPair->publicKey),
    ]);
});

it('delegates verification of a Verdict decision to attest using the configured fixed chain', function (): void {
    app(EvidenceRecorder::class)->record(new DecisionEvidence(
        envelopeId: 'verify-decision-1',
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'verify-arguments'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
    ));

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('Verdict evidence verification uses attest:verify for chain [verdict].')
        ->expectsOutputToContain('Verdict coverage: decisions and context releases are chained; provenance is not chained.')
        ->assertExitCode(0);
});

it('detects tampering of the attest envelope recorded for a Verdict decision', function (): void {
    app(EvidenceRecorder::class)->record(new DecisionEvidence(
        envelopeId: 'verify-corrupted-decision',
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'verify-corrupted-arguments'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
    ));

    $rawEnvelope = AttestEnvelope::query()
        ->where('chain_id', 'verdict')
        ->where('sequence', 1)
        ->value('raw_envelope');

    expect($rawEnvelope)->toBeString();

    $tamperedEnvelope = json_decode($rawEnvelope, associative: true, flags: JSON_THROW_ON_ERROR);
    $tamperedEnvelope['sig'] = 'base64:'.base64_encode(str_repeat("\x00", 64));

    AttestEnvelope::query()
        ->where('chain_id', 'verdict')
        ->where('sequence', 1)
        ->update(['raw_envelope' => json_encode($tamperedEnvelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('Verdict evidence verification uses attest:verify for chain [verdict].')
        ->assertExitCode(4);
});

it('requires a concrete chain when Verdict uses a chain resolver', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('A deployment using verdict.evidence.attest.chain_resolver must name its concrete chain with --chain.')
        ->assertExitCode(1);
});

it('emits parseable delegated JSON without Verdict preamble text', function (): void {
    app(EvidenceRecorder::class)->record(new DecisionEvidence(
        envelopeId: 'verify-json-decision',
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'verify-json-arguments'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
    ));

    expect(Artisan::call('verdict:evidence:verify', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
        ->toHaveKey('format_version', 'attest.cli.result.v1');
});

it('reports provenance coverage using the recorder\'s boolean coercion', function (): void {
    config()->set('verdict.evidence.attest.chain_provenance', '1');
    app(EvidenceRecorder::class)->record(new DecisionEvidence(
        envelopeId: 'verify-provenance-coverage',
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'verify-provenance-coverage-arguments'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
    ));

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('Verdict coverage: decisions, context releases, and provenance are chained.')
        ->assertExitCode(0);
});

it('fails closed when both fixed and resolver chain topology are configured', function (): void {
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('Verdict attest chain topology is invalid: configure exactly one of verdict.evidence.attest.chain or verdict.evidence.attest.chain_resolver.')
        ->assertExitCode(1);
});

it('accepts an effective Attest recorder bound independently of its configured class', function (): void {
    $recorder = app(EvidenceRecorder::class);
    app()->instance(EvidenceRecorder::class, $recorder);
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $recorder->record(verificationDecisionEvidence('verify-bound-recorder'));

    $this->artisan('verdict:evidence:verify')
        ->expectsOutputToContain('Verdict evidence verification uses attest:verify for chain [verdict].')
        ->assertExitCode(0);
});

function verificationDecisionEvidence(string $envelopeId): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: $envelopeId,
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', $envelopeId.'-arguments'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-25T00:00:00+00:00'),
    );
}
