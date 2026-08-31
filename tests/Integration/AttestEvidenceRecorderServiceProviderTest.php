<?php

declare(strict_types=1);

use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Tests\Support\AbstractAttestChainResolver;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\Tests\Support\StaticAttestChainResolver;
use Fissible\Verdict\Tests\Support\ThrowingAttestChainResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict');

    // Verdict has no loadMigrationsFrom(), so its migrations do not run here. Build the evidence
    // and derivations tables from the published migration stubs (#359) so the container-built
    // recorder's fallback writes hit the real shipped column set — this was a third hand-rolled
    // copy of that schema, carrying the same unsignedInteger/unsignedBigInteger drift as the two
    // the issue named.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));
});

function attestDecisionEvidence(string $envelopeId): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: $envelopeId,
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'args'),
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
        recordedAt: new DateTimeImmutable,
    );
}

it('resolves an AttestEvidenceRecorder from config and records a real decision', function (): void {
    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(attestDecisionEvidence('env-int-1'));

    $envelope = AttestEnvelope::query()
        ->forCorrelation('env-int-1')
        ->first();

    expect($envelope)->not->toBeNull()
        ->and($envelope->type)->toBe('verdict.decision')
        ->and($envelope->chain_id)->toBe('verdict');
});

it('routes provenance through the real DatabaseEvidenceRecorder fallback the container built', function (): void {
    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $entry = new ProvenanceEntry(
        correlationId: 'inv-int-1',
        source: Source::external('search-api'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ToolResult,
        contentFingerprint: hash('sha256', 'doc'),
        componentLabel: null,
        componentFingerprint: null,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
    );

    $recorder->recordProvenance($entry);

    // chain_provenance defaults to false, so this must land in verdict_evidence via the
    // DatabaseEvidenceRecorder fallback and read back through it, not through the chain.
    expect($recorder->provenanceFor('inv-int-1'))->toEqual([$entry])
        ->and(AttestEnvelope::query()->forCorrelation('inv-int-1')->first())->toBeNull();

    $row = app(DatabaseManager::class)->connection()
        ->table(verdictTable('evidence'))
        ->where('correlation_id', 'inv-int-1')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->record_type)->toBe('provenance')
        ->and($row->content_fingerprint)->toBe(hash('sha256', 'doc'));
});

it('throws when neither chain nor chain_resolver is configured', function (): void {
    config()->set('verdict.evidence.attest.chain', null);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'AttestEvidenceRecorder requires an explicit chain-topology decision');
});

it('throws when chain is set to an empty string', function (): void {
    // VERDICT_ATTEST_CHAIN= (blank) is "I have not decided" spelled differently, not a
    // chain id — it must fail exactly like an unset chain rather than chaining to ''.
    config()->set('verdict.evidence.attest.chain', '');

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'must contain a chain id string');
});

it('throws when chain_resolver is set to an empty string', function (): void {
    // Mirrors the chain case above: VERDICT_ATTEST_CHAIN_RESOLVER= (blank) is "I have not
    // decided" spelled differently, not a class name. Without this, a deployment that sets
    // chain=verdict and blanks chain_resolver would hit the misleading "received both"
    // check instead of a message describing what's actually wrong.
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', '');

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'must contain a class name');
});

it('throws when both chain and chain_resolver are configured', function (): void {
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'AttestEvidenceRecorder received both');
});

it('throws when chain_resolver does not implement AttestChainResolver', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', stdClass::class);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'The ['.stdClass::class.'] chain resolver must implement');
});

it('throws a clean LogicException, not an uncaught framework exception, when chain_resolver names a class that does not exist', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', 'Fissible\\Verdict\\Tests\\Support\\ThisClassDoesNotExist');

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'does not exist or could not be autoloaded');
});

it('throws when chain_resolver names a class that exists but cannot be instantiated', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', AbstractAttestChainResolver::class);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'must be an instantiable class');
});

it('resolves the chain id through the configured resolver class fresh on every write', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);
    StaticAttestChainResolver::$calls = 0;
    StaticAttestChainResolver::$instanceIds = [];
    StaticAttestChainResolver::$constructions = 0;

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(attestDecisionEvidence('env-tenant-1'));
    $recorder->record(attestDecisionEvidence('env-tenant-2'));

    $first = AttestEnvelope::query()->forCorrelation('env-tenant-1')->first();
    $second = AttestEnvelope::query()->forCorrelation('env-tenant-2')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->chain_id)->toBe('tenant:1')
        ->and($second->chain_id)->toBe('tenant:2')
        ->and(StaticAttestChainResolver::$instanceIds)->toHaveCount(2)
        ->and(StaticAttestChainResolver::$instanceIds[0])->not->toBe(StaticAttestChainResolver::$instanceIds[1]);
});

it('propagates a throwing chain_resolver into the existing resolverFailed/phase-tagged gap handling', function (): void {
    Event::fake([ChainWriteFailed::class]);

    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', ThrowingAttestChainResolver::class);

    $recorder = app(EvidenceRecorder::class);
    $recorder->record(attestDecisionEvidence('env-resolver-fail'));

    $row = app(DatabaseManager::class)->connection()
        ->table(verdictTable('evidence'))
        ->where('correlation_id', 'env-resolver-fail')
        ->where('record_type', 'chain_gap')
        ->first();

    expect($row)->not->toBeNull();

    $reason = json_decode((string) $row->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['phase'])->toBe('resolve_chain_id')
        ->and($reason['attempts'])->toBe(0);

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->phase === 'resolve_chain_id'
        && $e->attempts === 0);
});
