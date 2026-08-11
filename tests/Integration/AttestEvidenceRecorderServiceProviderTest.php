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
use Fissible\Verdict\Tests\Support\StaticAttestChainResolver;
use Fissible\Verdict\Tests\Support\ThrowingAttestChainResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict');

    // Verdict has no loadMigrationsFrom(), so its migrations do not run here. Build the
    // full composed verdict_evidence schema by hand, exactly as tests/Feature does, so
    // the container-built recorder's fallback writes hit the real shipped column set.
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_evidence');
    $schema->dropIfExists('verdict_provenance_derivations');
    $schema->create('verdict_evidence', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
        $table->string('invocation_id')->nullable();
        $table->string('capability')->nullable();
        $table->string('stage', 32);
        $table->string('disposition', 32);
        $table->text('reason')->nullable();
        $table->string('source')->nullable();
        $table->string('destination')->nullable();
        $table->string('trust_zone')->nullable();
        $table->string('trust', 32)->nullable();
        $table->string('data_class', 32)->nullable();
        $table->char('argument_fingerprint', 64)->nullable();
        $table->char('idempotency_key_fingerprint', 64)->nullable();
        $table->char('approval_receipt_fingerprint', 64)->nullable();
        $table->string('approval_phase', 32)->nullable();
        $table->string('approval_outcome', 32)->nullable();
        $table->string('target_policy')->nullable();
        $table->string('target_strategy', 32)->nullable();
        $table->char('proposal_target_identity_fingerprint', 64)->nullable();
        $table->char('execution_target_identity_fingerprint', 64)->nullable();
        $table->boolean('target_identity_matched')->nullable();
        $table->char('rate_limit_key_fingerprint', 64)->nullable();
        $table->string('rate_limit_policy')->nullable();
        $table->unsignedInteger('rate_limit_limit')->nullable();
        $table->unsignedInteger('rate_limit_remaining')->nullable();
        $table->timestamp('rate_limit_reset_at')->nullable();
        $table->char('execution_claim_fingerprint', 64)->nullable();
        $table->char('execution_claim_binding_fingerprint', 64)->nullable();
        $table->string('execution_claim_policy')->nullable();
        $table->string('execution_claim_status', 24)->nullable();
        $table->unsignedInteger('execution_claim_attempt')->nullable();
        $table->json('requested_path_fingerprints')->nullable();
        $table->json('released_path_fingerprints')->nullable();
        $table->json('transform_fingerprints')->nullable();
        $table->json('transformed_path_fingerprints')->nullable();
        $table->unsignedInteger('transformation_count')->default(0);
        $table->char('payload_fingerprint', 64)->nullable();
        $table->string('channel', 32)->nullable();
        $table->string('component_label')->nullable();
        $table->char('component_fingerprint', 64)->nullable();
        $table->char('content_fingerprint', 64)->nullable();
        $table->timestamp('recorded_at');
    });
    $schema->create('verdict_provenance_derivations', function (Blueprint $table): void {
        $table->string('correlation_id');
        $table->char('child_content_fingerprint', 64);
        $table->char('parent_content_fingerprint', 64);
        $table->string('kind', 32);
        $table->timestamp('recorded_at');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_evidence');
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_provenance_derivations');
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
        ->and($envelope->type)->toBe('verdict.decision');
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
        ->table('verdict_evidence')
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
        ->toThrow(LogicException::class, 'chain resolver must implement');
});

it('resolves the chain id through the configured resolver class fresh on every write', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);
    StaticAttestChainResolver::$calls = 0;

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(attestDecisionEvidence('env-tenant-1'));
    $recorder->record(attestDecisionEvidence('env-tenant-2'));

    $first = AttestEnvelope::query()->forCorrelation('env-tenant-1')->first();
    $second = AttestEnvelope::query()->forCorrelation('env-tenant-2')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->chain_id)->toBe('tenant:1')
        ->and($second->chain_id)->toBe('tenant:2');
});

it('propagates a throwing chain_resolver into the existing resolverFailed/phase-tagged gap handling', function (): void {
    Event::fake([ChainWriteFailed::class]);

    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', ThrowingAttestChainResolver::class);

    $recorder = app(EvidenceRecorder::class);
    $recorder->record(attestDecisionEvidence('env-resolver-fail'));

    $row = app(DatabaseManager::class)->connection()
        ->table('verdict_evidence')
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
