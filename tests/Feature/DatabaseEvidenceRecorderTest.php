<?php

declare(strict_types=1);

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
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
        $table->json('requested_path_fingerprints')->nullable();
        $table->json('released_path_fingerprints')->nullable();
        $table->char('payload_fingerprint', 64)->nullable();
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
        recordedAt: $recordedAt,
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
        ->and($serialized)->not->toContain('first_name')
        ->and($serialized)->not->toContain('orders.0.status')
        ->and($serialized)->not->toContain('Avery');
});
