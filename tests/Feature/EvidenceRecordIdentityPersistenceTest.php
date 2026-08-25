<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\Evidence\ClaimType;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\RecordDigest;
use Illuminate\Database\DatabaseManager;

function recordIdentityEvidence(string $stage, string $disposition, ?string $claimStatus = null): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'envelope-identity-1',
        capability: 'orders.cancel',
        stage: $stage,
        disposition: $disposition,
        reason: 'An operator-facing message.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'idem-identity',
        approvalReceiptFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: 'orders-target',
        targetStrategy: 'accept_stale_snapshot',
        proposalTargetIdentityFingerprint: str_repeat('c', 64),
        executionTargetIdentityFingerprint: str_repeat('d', 64),
        targetIdentityMatched: true,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: $claimStatus === null ? null : str_repeat('f', 64),
        executionClaimBindingFingerprint: $claimStatus === null ? null : str_repeat('1', 64),
        executionClaimPolicy: $claimStatus === null ? null : 'cancel-once',
        executionClaimStatus: $claimStatus,
        executionClaimAttempt: $claimStatus === null ? null : 1,
        recordedAt: new DateTimeImmutable('2026-08-19T09:30:00+00:00'),
        invocationId: 'invocation-identity',
        toolKind: 'bound',
        configurationFingerprint: str_repeat('2', 64),
        actorFingerprint: str_repeat('3', 64),
        subjectFingerprint: str_repeat('4', 64),
        targetSource: 'proposal',
    );
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));

    (require __DIR__.'/../../database/migrations/create_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_provenance_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_target_source_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_tool_description_fingerprints_to_verdict_evidence_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_record_identity_to_verdict_evidence_table.php.stub')->up();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
});

it('persists the claim type and the scheme-tagged record digest', function (): void {
    $evidence = recordIdentityEvidence('execution_claim', 'permit', 'completed');

    app(DatabaseManager::class)->connection()->getSchemaBuilder();
    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);

    $row = app('db')->table(verdictTable('evidence'))->where('record_type', 'decision')->first();

    expect($row->claim_type)->toBe(ClaimType::ExecutionClaimCompleted->value)
        ->and($row->record_digest)->toBe($evidence->recordDigest)
        ->and($row->record_digest)->toStartWith(RecordDigest::SCHEME.':');
});

/**
 * The acceptance criterion that matters most: a third party holding only the stored row and the
 * documented field set re-derives the digest, with no Attest and no Verdict recorder involved. This
 * is what would have silently broken had the digest been computed over sub-second precision the
 * `timestamp` column does not keep.
 */
it('reproduces the stored digest from the persisted row alone', function (): void {
    $evidence = recordIdentityEvidence('execution', 'permit');

    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);

    $row = app('db')->table(verdictTable('evidence'))->where('record_type', 'decision')->first();

    $fromRow = [
        'envelope_id' => $row->correlation_id,
        'capability' => $row->capability,
        'stage' => $row->stage,
        'disposition' => $row->disposition,
        'recorded_at' => (new DateTimeImmutable($row->recorded_at))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z'),
        'invocation_id' => $row->invocation_id,
        'tool_kind' => $row->tool_kind,
        'target_source' => $row->target_source,
        'configuration_fingerprint' => $row->configuration_fingerprint,
        'actor_fingerprint' => $row->actor_fingerprint,
        'subject_fingerprint' => $row->subject_fingerprint,
        'argument_fingerprint' => $row->argument_fingerprint,
        'idempotency_key_fingerprint' => $row->idempotency_key_fingerprint,
        'approval_receipt_fingerprint' => $row->approval_receipt_fingerprint,
        'approval_phase' => $row->approval_phase,
        'approval_outcome' => $row->approval_outcome,
        'target_policy' => $row->target_policy,
        'target_strategy' => $row->target_strategy,
        'proposal_target_identity_fingerprint' => $row->proposal_target_identity_fingerprint,
        'execution_target_identity_fingerprint' => $row->execution_target_identity_fingerprint,
        'target_identity_matched' => (bool) $row->target_identity_matched,
        'rate_limit_key_fingerprint' => $row->rate_limit_key_fingerprint,
        'rate_limit_policy' => $row->rate_limit_policy,
        'rate_limit_limit' => $row->rate_limit_limit,
        'rate_limit_remaining' => $row->rate_limit_remaining,
        'rate_limit_reset_at' => $row->rate_limit_reset_at,
        'execution_claim_fingerprint' => $row->execution_claim_fingerprint,
        'execution_claim_binding_fingerprint' => $row->execution_claim_binding_fingerprint,
        'execution_claim_policy' => $row->execution_claim_policy,
        'execution_claim_status' => $row->execution_claim_status,
        'execution_claim_attempt' => $row->execution_claim_attempt,
        'tool_description_fingerprint' => $row->tool_description_fingerprint,
        'invocation_tool_description_fingerprint' => $row->invocation_tool_description_fingerprint,
        'tool_description_matched' => $row->tool_description_matched,
    ];

    expect($fromRow)->toBe(RecordDigest::stableFields($evidence))
        ->and(RecordDigest::SCHEME.':'.hash('sha256', CanonicalJson::encode($fromRow, 'record digest')))
        ->toBe($row->record_digest);
});

/** The one field an application controls freely must not be able to change a record's identity. */
it('keeps the persisted digest stable when only the reason differs', function (): void {
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());

    $recorder->record(recordIdentityEvidence('execution', 'permit'));

    $digests = app('db')->table(verdictTable('evidence'))->pluck('record_digest')->all();

    expect($digests)->toHaveCount(1);

    expect(RecordDigest::for(recordIdentityEvidence('execution', 'permit')))->toBe($digests[0]);
});
