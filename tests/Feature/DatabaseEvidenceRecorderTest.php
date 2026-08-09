<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_evidence');
    $schema->create('verdict_evidence', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
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
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_evidence');
});

function databaseEvidenceRecorder(): DatabaseEvidenceRecorder
{
    return new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
}

it('persists decision evidence while hashing the tool-call key', function (): void {
    $recordedAt = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $evidence = new DecisionEvidence(
        envelopeId: 'envelope-123',
        capability: 'orders.view',
        stage: 'proposal',
        disposition: 'deny',
        reason: 'Order belongs to another customer.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'provider-tool-call-secret',
        approvalReceiptFingerprint: null,
        approvalPhase: 'execution_validation',
        approvalOutcome: 'approved',
        targetPolicy: 'order-primary-key',
        targetStrategy: 'refresh',
        proposalTargetIdentityFingerprint: str_repeat('e', 64),
        executionTargetIdentityFingerprint: str_repeat('e', 64),
        targetIdentityMatched: true,
        rateLimitKeyFingerprint: str_repeat('b', 64),
        rateLimitPolicy: 'per-customer',
        rateLimitLimit: 5,
        rateLimitRemaining: 4,
        rateLimitResetAt: new DateTimeImmutable('2026-08-01 12:01:00', new DateTimeZone('UTC')),
        executionClaimFingerprint: str_repeat('c', 64),
        executionClaimBindingFingerprint: str_repeat('d', 64),
        executionClaimPolicy: 'cancel-order',
        executionClaimStatus: 'completed',
        executionClaimAttempt: 1,
        recordedAt: $recordedAt,
        invocationId: 'invocation-123',
    );

    databaseEvidenceRecorder()->record($evidence);

    $row = app(DatabaseManager::class)->connection()->table('verdict_evidence')->first();

    expect($row)->toBeInstanceOf(stdClass::class);

    if (! $row instanceof stdClass) {
        return;
    }

    expect((string) $row->record_type)->toBe('decision')
        ->and((string) $row->correlation_id)->toBe('envelope-123')
        ->and((string) $row->capability)->toBe('orders.view')
        ->and((string) $row->disposition)->toBe('deny')
        ->and((string) $row->argument_fingerprint)->toBe(str_repeat('a', 64))
        ->and((string) $row->rate_limit_key_fingerprint)->toBe(str_repeat('b', 64))
        ->and((string) $row->approval_phase)->toBe('execution_validation')
        ->and((string) $row->approval_outcome)->toBe('approved')
        ->and((string) $row->target_policy)->toBe('order-primary-key')
        ->and((int) $row->target_identity_matched)->toBe(1)
        ->and((string) $row->rate_limit_policy)->toBe('per-customer')
        ->and((int) $row->rate_limit_remaining)->toBe(4)
        ->and((string) $row->execution_claim_fingerprint)->toBe(str_repeat('c', 64))
        ->and((string) $row->execution_claim_status)->toBe('completed')
        ->and((string) $row->idempotency_key_fingerprint)
        ->toBe(hash('sha256', 'provider-tool-call-secret'))
        ->not->toBe('provider-tool-call-secret');
});

it('persists context-release evidence without raw paths or payload values', function (): void {
    $evidence = ContextReleaseEvidence::make(
        source: Source::application('customer-profile'),
        destination: Destination::connection('ollama-local', 'local-machine'),
        trust: Trust::Trusted,
        dataClass: DataClass::PII,
        permitted: true,
        reason: 'Permitted release.',
        requestedPaths: ['first_name', 'orders.*.status'],
        releasedPaths: ['first_name', 'orders.0.status'],
        payloadFingerprint: str_repeat('b', 64),
        recordedAt: new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')),
        transformNames: ['structured_redaction'],
        transformedPaths: ['email'],
    );

    databaseEvidenceRecorder()->recordRelease($evidence);

    $row = app(DatabaseManager::class)->connection()->table('verdict_evidence')->first();

    expect($row)->toBeInstanceOf(stdClass::class);

    if (! $row instanceof stdClass) {
        return;
    }

    $serialized = json_encode($row, JSON_THROW_ON_ERROR);
    $requested = json_decode((string) $row->requested_path_fingerprints, true, flags: JSON_THROW_ON_ERROR);
    $released = json_decode((string) $row->released_path_fingerprints, true, flags: JSON_THROW_ON_ERROR);
    $transforms = json_decode((string) $row->transform_fingerprints, true, flags: JSON_THROW_ON_ERROR);
    $transformedPaths = json_decode((string) $row->transformed_path_fingerprints, true, flags: JSON_THROW_ON_ERROR);

    expect((string) $row->record_type)->toBe('context_release')
        ->and((string) $row->destination)->toBe('local-machine:ollama-local')
        ->and((string) $row->trust)->toBe('trusted')
        ->and((string) $row->data_class)->toBe('pii')
        ->and($requested)->toBe([
            hash('sha256', 'first_name'),
            hash('sha256', 'orders.*.status'),
        ])
        ->and($released)->toBe([
            hash('sha256', 'first_name'),
            hash('sha256', 'orders.0.status'),
        ])
        ->and($transforms)->toBe([hash('sha256', 'structured_redaction')])
        ->and($transformedPaths)->toBe([hash('sha256', 'email')])
        ->and((int) $row->transformation_count)->toBe(1)
        ->and($serialized)->not->toContain('first_name')
        ->and($serialized)->not->toContain('orders.0.status')
        ->and($serialized)->not->toContain('Avery');
});

it('persists and retrieves redacted provenance without mixing correlations', function (): void {
    $recorder = databaseEvidenceRecorder();
    $recordedAt = new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
    $rawValues = [
        'private prompt text',
        'confidential document body',
        'secret tool response',
    ];
    $entries = [
        new ProvenanceEntry(
            correlationId: 'invocation-1',
            source: Source::user('customer'),
            trust: Trust::Untrusted,
            dataClass: DataClass::PII,
            channel: ContextChannel::UserInput,
            contentFingerprint: ContentFingerprint::make($rawValues[0]),
            componentLabel: null,
            componentFingerprint: null,
            recordedAt: $recordedAt,
        ),
        new ProvenanceEntry(
            correlationId: 'invocation-1',
            source: Source::external('knowledge-base'),
            trust: Trust::Untrusted,
            dataClass: DataClass::Internal,
            channel: ContextChannel::RetrievedDocument,
            contentFingerprint: ContentFingerprint::make($rawValues[1]),
            componentLabel: 'retriever',
            componentFingerprint: ContentFingerprint::make('v2.1.0'),
            recordedAt: $recordedAt->modify('+1 second'),
        ),
        new ProvenanceEntry(
            correlationId: 'invocation-2',
            source: Source::external('payment-api'),
            trust: Trust::Trusted,
            dataClass: DataClass::Sensitive,
            channel: ContextChannel::ToolResult,
            contentFingerprint: ContentFingerprint::make($rawValues[2]),
            componentLabel: 'payment-client',
            componentFingerprint: ContentFingerprint::make('v4'),
            recordedAt: $recordedAt->modify('+2 seconds'),
        ),
    ];

    foreach ($entries as $entry) {
        $recorder->recordProvenance($entry);
    }

    $rows = app(DatabaseManager::class)->connection()->table('verdict_evidence')->get();
    $serializedRows = json_encode($rows, JSON_THROW_ON_ERROR);
    $correlated = $recorder->provenanceFor('invocation-1');

    expect($rows)->toHaveCount(3)
        ->and($correlated)->toHaveCount(2)
        ->and(array_column($correlated, 'correlationId'))->each->toBe('invocation-1');

    expect($correlated[1]->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($correlated[1]->componentLabel)->toBe('retriever')
        ->and($correlated[1]->componentFingerprint)->toBe(ContentFingerprint::make('v2.1.0'))
        ->and($serializedRows)->not->toContain($rawValues[0])
        ->and($serializedRows)->not->toContain($rawValues[1])
        ->and($serializedRows)->not->toContain($rawValues[2])
        ->and($serializedRows)->not->toContain('v2.1.0');
});
