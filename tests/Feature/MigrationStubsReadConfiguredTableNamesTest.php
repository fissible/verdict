<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Illuminate\Database\DatabaseManager;

/**
 * #290: the config allows renaming every security-state and evidence table, and the stores honour
 * it — but the published migration stubs hardcoded the default names, so a config-only rename
 * produced stores pointed at tables `migrate` never created, failing at first write. These tests
 * set non-default names and run the stubs; they are the tests the literal stubs fail.
 *
 * Store behaviour under renamed tables is not re-proven per store here — the stores already read
 * the config keys (the provider wires them) and their own suites cover behaviour. The one store
 * exercised end to end is the evidence recorder, because `derivations_table` is the key this issue
 * introduces: the stub and the recorder gained it together, and drift between them is the new risk.
 */
function renamedTablesEvidence(): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'envelope-renamed-1',
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'deny',
        reason: 'An operator-facing message.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'idem-renamed',
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
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-24T09:30:00+00:00'),
        invocationId: 'invocation-renamed',
        toolKind: 'bound',
        configurationFingerprint: str_repeat('2', 64),
        actorFingerprint: str_repeat('3', 64),
        subjectFingerprint: str_repeat('4', 64),
        targetSource: 'proposal',
    );
}

function verdictRenamedTableConfig(): array
{
    return [
        'verdict.capability_configurations.table' => 'renamed_capability_configurations',
        'verdict.approvals.table' => 'renamed_approval_receipts',
        'verdict.evidence.table' => 'renamed_evidence',
        'verdict.evidence.derivations_table' => 'renamed_provenance_derivations',
        'verdict.rate_limits.table' => 'renamed_rate_limit_buckets',
        'verdict.execution_claims.table' => 'renamed_execution_claims',
    ];
}

/** @return array<string, string> configured name => create stub */
function verdictCreateStubsByRenamedTable(): array
{
    return [
        'renamed_capability_configurations' => 'create_verdict_capability_configurations_table.php.stub',
        'renamed_approval_receipts' => 'create_verdict_approval_receipts_table.php.stub',
        'renamed_evidence' => 'create_verdict_evidence_table.php.stub',
        'renamed_provenance_derivations' => 'create_verdict_provenance_derivations_table.php.stub',
        'renamed_rate_limit_buckets' => 'create_verdict_rate_limit_buckets_table.php.stub',
        'renamed_execution_claims' => 'create_verdict_execution_claims_table.php.stub',
    ];
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach (array_keys(verdictCreateStubsByRenamedTable()) as $renamed) {
        $schema->dropIfExists($renamed);
        $schema->dropIfExists(str_replace('renamed_', 'verdict_', $renamed));
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach (array_keys(verdictCreateStubsByRenamedTable()) as $renamed) {
        $schema->dropIfExists($renamed);
    }
});

it('creates every table under its configured name, not the default', function (): void {
    foreach (verdictRenamedTableConfig() as $key => $name) {
        config()->set($key, $name);
    }

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach (verdictCreateStubsByRenamedTable() as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }

    foreach (verdictCreateStubsByRenamedTable() as $configured => $stub) {
        $default = str_replace(['create_', '_table.php.stub'], '', $stub);

        expect($schema->hasTable($configured))->toBeTrue("{$stub} did not create [{$configured}]")
            ->and($schema->hasTable($default))->toBeFalse("{$stub} created the default [{$default}] despite the configured name");
    }

    // down() must honour the same name: nothing may survive, and nothing default may be touched.
    foreach (verdictCreateStubsByRenamedTable() as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->down();
    }

    foreach (array_keys(verdictCreateStubsByRenamedTable()) as $configured) {
        expect($schema->hasTable($configured))->toBeFalse();
    }
});

it('applies the add_* stubs to the configured table name', function (): void {
    foreach (verdictRenamedTableConfig() as $key => $name) {
        config()->set($key, $name);
    }

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();

    expect($schema->hasColumn('renamed_approval_receipts', 'provenance'))->toBeTrue();

    (require __DIR__.'/../../database/migrations/create_verdict_approval_receipts_table.php.stub')->down();
});

it('records evidence and derivations into the renamed tables through the provider-wired recorder', function (): void {
    foreach (verdictRenamedTableConfig() as $key => $name) {
        config()->set($key, $name);
    }
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    foreach (['create_verdict_evidence_table.php.stub', 'create_verdict_provenance_derivations_table.php.stub'] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
    foreach ([
        'add_invocation_id_to_verdict_evidence_table.php.stub',
        'add_provenance_to_verdict_evidence_table.php.stub',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
        'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
        'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
        'add_tool_kind_to_verdict_evidence_table.php.stub',
        'add_target_source_to_verdict_evidence_table.php.stub',
        'add_record_identity_to_verdict_evidence_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(DatabaseEvidenceRecorder::class);

    $recorder->record(renamedTablesEvidence());

    expect($manager->connection()->table('renamed_evidence')->count())->toBe(1);

    foreach (['renamed_evidence', 'renamed_provenance_derivations'] as $table) {
        $schema->dropIfExists($table);
    }
});
