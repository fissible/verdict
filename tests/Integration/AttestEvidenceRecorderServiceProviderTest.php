<?php

declare(strict_types=1);

use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_evidence');
    $schema->create('verdict_evidence', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
        $table->string('stage', 32);
        $table->string('disposition', 32);
        $table->text('reason')->nullable();
        $table->timestamp('recorded_at');
    });
});

it('resolves an AttestEvidenceRecorder from config and records a real decision', function (): void {
    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(new DecisionEvidence(
        envelopeId: 'env-int-1',
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
    ));

    $envelope = AttestEnvelope::query()
        ->forCorrelation('env-int-1')
        ->first();

    expect($envelope)->not->toBeNull()
        ->and($envelope->type)->toBe('verdict.decision');
});
