<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #356: the evidence table is a base migration plus additive `add_*` migrations, and record() was
 * a flat insert naming every column. An install that updated without re-publishing and re-running
 * migrations therefore died with an unknown-column error on the decision path — every guarded
 * decision, on live traffic. DatabaseApprovalReceiptStore already introspects and degrades.
 *
 * Degrading is not free: the omitted columns are gone from retained evidence. So the property
 * these tests pin is an intersection, not merely "it did not throw" — every column the table
 * still has must be written, and only genuinely absent ones may be dropped. Silently writing a
 * sparse row would satisfy "no exception" while destroying the record the package exists to keep.
 */

/**
 * Which record types populate each additive column. A map rather than a decision/provenance
 * partition: a future column may be release-only, shared, or deliberately unpopulated, and the
 * guard below only requires that somebody classified it.
 */
const ADDITIVE_COLUMN_WRITERS = [
    'invocation_id' => ['decision', 'release', 'provenance'],
    'intent_id' => ['decision'],
    'tool_kind' => ['decision'],
    'configuration_fingerprint' => ['decision', 'release'],
    'actor_fingerprint' => ['decision'],
    'subject_fingerprint' => ['decision'],
    'target_source' => ['decision'],
    'tool_description_fingerprint' => ['decision'],
    'invocation_tool_description_fingerprint' => ['decision'],
    'tool_description_matched' => ['decision'],
    'claim_type' => ['decision'],
    'record_digest' => ['decision'],
    'channel' => ['provenance'],
    'component_label' => ['provenance'],
    'component_fingerprint' => ['provenance'],
    'content_fingerprint' => ['provenance'],
];

/**
 * Base-schema columns a decision row populates. Degradation must not touch these — they predate
 * every additive migration, so no lag can excuse dropping them.
 */
const BASE_DECISION_COLUMNS = [
    'id', 'record_type', 'correlation_id', 'capability', 'stage', 'disposition', 'reason',
    'argument_fingerprint', 'idempotency_key_fingerprint', 'approval_receipt_fingerprint',
    'approval_phase', 'approval_outcome', 'target_policy', 'target_strategy',
    'proposal_target_identity_fingerprint', 'execution_target_identity_fingerprint',
    'target_identity_matched', 'rate_limit_key_fingerprint', 'rate_limit_policy',
    'rate_limit_limit', 'rate_limit_remaining', 'rate_limit_reset_at',
    'execution_claim_fingerprint', 'execution_claim_binding_fingerprint',
    'execution_claim_policy', 'execution_claim_status', 'execution_claim_attempt',
    'recorded_at',
];

/** @return list<string> */
function additiveColumnsWrittenBy(string $recordType): array
{
    $columns = [];

    foreach (ADDITIVE_COLUMN_WRITERS as $column => $writers) {
        if (in_array($recordType, $writers, true)) {
            $columns[] = $column;
        }
    }

    return $columns;
}

function degradationEvidence(): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'envelope-356',
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'permit',
        reason: 'Within policy.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'tool-call-356',
        approvalReceiptFingerprint: str_repeat('9', 64),
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
        rateLimitResetAt: new DateTimeImmutable('2026-08-27 09:01:00', new DateTimeZone('UTC')),
        executionClaimFingerprint: str_repeat('c', 64),
        executionClaimBindingFingerprint: str_repeat('d', 64),
        executionClaimPolicy: 'cancel-order',
        executionClaimStatus: 'completed',
        executionClaimAttempt: 1,
        recordedAt: new DateTimeImmutable('2026-08-27 09:00:00', new DateTimeZone('UTC')),
        invocationId: 'invocation-356',
        toolKind: 'bound',
        configurationFingerprint: str_repeat('f', 64),
        actorFingerprint: hash('sha256', 'agent:1'),
        subjectFingerprint: hash('sha256', 'customer:2'),
        targetSource: 'resolved',
        toolDescriptionFingerprint: str_repeat('7', 64),
        invocationToolDescriptionFingerprint: str_repeat('7', 64),
        toolDescriptionMatched: true,
        intentId: str_repeat('1', 64),
    );
}

/**
 * What every column of a decision row must contain for the fixture above. Asserting exact values
 * rather than "not null" is the point: a filter that wrote constants, or reused one fingerprint
 * for every fingerprint column, would satisfy a non-null check while destroying the record.
 *
 * @return array<string, string>
 */
function expectedDecisionValues(DecisionEvidence $evidence): array
{
    return [
        'record_type' => 'decision',
        'correlation_id' => 'envelope-356',
        'capability' => 'orders.cancel',
        'stage' => 'proposal',
        'disposition' => 'permit',
        'reason' => 'Within policy.',
        'argument_fingerprint' => str_repeat('a', 64),
        'idempotency_key_fingerprint' => hash('sha256', 'tool-call-356'),
        'approval_receipt_fingerprint' => str_repeat('9', 64),
        'approval_phase' => 'execution_validation',
        'approval_outcome' => 'approved',
        'target_policy' => 'order-primary-key',
        'target_strategy' => 'refresh',
        'proposal_target_identity_fingerprint' => str_repeat('e', 64),
        'execution_target_identity_fingerprint' => str_repeat('e', 64),
        'target_identity_matched' => '1',
        'rate_limit_key_fingerprint' => str_repeat('b', 64),
        'rate_limit_policy' => 'per-customer',
        'rate_limit_limit' => '5',
        'rate_limit_remaining' => '4',
        'rate_limit_reset_at' => '2026-08-27 09:01:00',
        'execution_claim_fingerprint' => str_repeat('c', 64),
        'execution_claim_binding_fingerprint' => str_repeat('d', 64),
        'execution_claim_policy' => 'cancel-order',
        'execution_claim_status' => 'completed',
        'execution_claim_attempt' => '1',
        'recorded_at' => '2026-08-27 09:00:00',
        'invocation_id' => 'invocation-356',
        'intent_id' => str_repeat('1', 64),
        'tool_kind' => 'bound',
        'configuration_fingerprint' => str_repeat('f', 64),
        'actor_fingerprint' => hash('sha256', 'agent:1'),
        'subject_fingerprint' => hash('sha256', 'customer:2'),
        'target_source' => 'resolved',
        'tool_description_fingerprint' => str_repeat('7', 64),
        'invocation_tool_description_fingerprint' => str_repeat('7', 64),
        'tool_description_matched' => '1',
        // Derived by DecisionEvidence itself, so taken from the object rather than restated.
        'claim_type' => (string) $evidence->claimType?->value,
        'record_digest' => $evidence->recordDigest,
        'transformation_count' => '0',
    ];
}

function degradationRelease(): ContextReleaseEvidence
{
    return new ContextReleaseEvidence(
        source: 'orders.lookup',
        destination: 'provider',
        trustZone: 'external',
        trust: Trust::Untrusted,
        dataClass: DataClass::PII,
        disposition: 'permit',
        reason: 'Released under policy.',
        requestedPathFingerprints: [str_repeat('b', 64)],
        releasedPathFingerprints: [str_repeat('b', 64)],
        payloadFingerprint: str_repeat('c', 64),
        recordedAt: new DateTimeImmutable('2026-08-27 09:00:00', new DateTimeZone('UTC')),
        invocationId: 'invocation-356',
        configurationFingerprint: str_repeat('f', 64),
    );
}

/** @return array<string, string> */
function expectedReleaseValues(): array
{
    return [
        'record_type' => 'context_release',
        'stage' => 'release',
        'disposition' => 'permit',
        'reason' => 'Released under policy.',
        'source' => 'orders.lookup',
        'destination' => 'provider',
        'trust_zone' => 'external',
        'trust' => 'untrusted',
        'data_class' => 'pii',
        'requested_path_fingerprints' => json_encode([str_repeat('b', 64)]),
        'released_path_fingerprints' => json_encode([str_repeat('b', 64)]),
        'transform_fingerprints' => json_encode([]),
        'transformed_path_fingerprints' => json_encode([]),
        'transformation_count' => '0',
        'payload_fingerprint' => str_repeat('c', 64),
        'recorded_at' => '2026-08-27 09:00:00',
        'invocation_id' => 'invocation-356',
        'configuration_fingerprint' => str_repeat('f', 64),
    ];
}

function degradationProvenance(): ProvenanceEntry
{
    return new ProvenanceEntry(
        correlationId: 'invocation-356',
        source: Source::user('customer'),
        trust: Trust::Untrusted,
        dataClass: DataClass::PII,
        channel: ContextChannel::UserInput,
        contentFingerprint: ContentFingerprint::make('hello'),
        componentLabel: 'prompt',
        componentFingerprint: str_repeat('8', 64),
        recordedAt: new DateTimeImmutable('2026-08-27 09:00:00', new DateTimeZone('UTC')),
    );
}

function degradationRecorder(): DatabaseEvidenceRecorder
{
    return new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
}

function evidenceRows(): Collection
{
    return app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->get();
}

/**
 * Every column not in the expected map must be null. Asserting the absent half of the row shape
 * is what stops a recorder inventing release or provenance data on a decision row (or the
 * reverse) — the expected map alone only proves the fields it names.
 *
 * @param  array<string, mixed>  $row
 * @param  array<string, string>  $expected
 */
function expectNothingElseWritten(array $row, array $expected): void
{
    foreach ($row as $column => $value) {
        if ($column === 'id' || array_key_exists($column, $expected)) {
            continue;
        }

        expect($value)->toBeNull("column [{$column}] should not have been written");
    }
}

/** The single evidence row a single write must have produced — no more, no fewer. */
function singleEvidenceRow(): array
{
    $rows = evidenceRows();

    expect($rows)->toHaveCount(1, 'exactly one evidence row per write');

    return (array) $rows->first();
}

function evidenceColumns(): array
{
    return app(DatabaseManager::class)->connection()->getSchemaBuilder()
        ->getColumnListing(verdictTable('evidence'));
}

afterEach(function (): void {
    EvidenceTableSchema::drop();
});

it('classifies every additive evidence column by the record types that populate it', function (): void {
    EvidenceTableSchema::createComplete();

    $classified = array_keys(ADDITIVE_COLUMN_WRITERS);
    sort($classified);

    // Coverage, not a partition: when a new add_* migration lands this fails until someone says
    // which record types populate the column. Without it a new column could be dropped by the
    // recorder forever and no test would notice.
    expect($classified)->toBe(EvidenceTableSchema::additiveColumns());
});

it('writes every additive column a decision populates when the table is current', function (): void {
    EvidenceTableSchema::createComplete();

    $evidence = degradationEvidence();
    degradationRecorder()->record($evidence);

    $row = singleEvidenceRow();

    // The exhaustive control. An implementation that "degraded" by keeping only a handful of
    // columns would pass every lagging-table test below and fail here.
    foreach (expectedDecisionValues($evidence) as $column => $expected) {
        expect((string) $row[$column])->toBe($expected, "column [{$column}]");
    }

    expectNothingElseWritten($row, expectedDecisionValues($evidence));

    expect((string) $row['id'])->not->toBe('');
});

it('writes every surviving column when the table lags a migration', function (array $without): void {
    EvidenceTableSchema::createWithout($without);

    $evidence = degradationEvidence();
    degradationRecorder()->record($evidence);

    $row = singleEvidenceRow();
    $present = evidenceColumns();
    $missing = EvidenceTableSchema::missingColumns($without);

    // The intersection property: present means written. Base-schema columns are included because
    // no migration lag can excuse dropping them — a filter that kept correlation_id and the
    // additive set while losing capability, reason, approval or rate-limit evidence would
    // otherwise pass.
    foreach (expectedDecisionValues($evidence) as $column => $expected) {
        if (in_array($column, $present, true)) {
            expect((string) $row[$column])->toBe($expected, "surviving column [{$column}]");
        }
    }

    expectNothingElseWritten($row, expectedDecisionValues($evidence));

    expect(array_intersect($missing, $present))->toBe([]);
})->with([
    'intent id' => [['intent_id']],
    'actor and subject fingerprints' => [['actor_fingerprint']],
    'configuration fingerprint' => [['configuration_fingerprint']],
    'invocation id' => [['invocation_id']],
    'record identity' => [['record_digest']],
    'tool kind' => [['tool_kind']],
    'target source' => [['target_source']],
    'tool description fingerprints' => [['tool_description_fingerprint']],
    'provenance columns' => [['content_fingerprint']],
    'every additive migration' => [[
        'intent_id', 'actor_fingerprint', 'configuration_fingerprint', 'invocation_id',
        'record_digest', 'tool_kind', 'target_source', 'tool_description_fingerprint',
        'content_fingerprint',
    ]],
]);

it('writes every release column when the table is current', function (): void {
    EvidenceTableSchema::createComplete();

    degradationRecorder()->recordRelease(degradationRelease());

    $row = singleEvidenceRow();

    // The release control, including invocation_id and configuration_fingerprint — the two the
    // lag case below removes. Without this, an implementation that always dropped them would pass.
    foreach (expectedReleaseValues() as $column => $expected) {
        expect((string) $row[$column])->toBe($expected, "column [{$column}]");
    }

    expectNothingElseWritten($row, expectedReleaseValues());
});

it('retains every surviving release column whichever migration is missing', function (string $migration): void {
    EvidenceTableSchema::createWithoutMigration($migration);

    degradationRecorder()->recordRelease(degradationRelease());

    $row = singleEvidenceRow();
    $present = evidenceColumns();

    // Every additive migration, not a sample: a recorder that kept releases only when the
    // release-specific columns were the missing ones, and dropped them under any other lag,
    // would pass a sampled dataset.
    foreach (expectedReleaseValues() as $column => $expected) {
        if (in_array($column, $present, true)) {
            expect((string) $row[$column])->toBe($expected, "surviving column [{$column}]");
        }
    }

    expectNothingElseWritten($row, expectedReleaseValues());
})->with(EvidenceTableSchema::additiveMigrationNames());

it('returns provenance recorded on a complete table', function (): void {
    EvidenceTableSchema::createComplete();

    $recorder = degradationRecorder();
    $recorder->recordProvenance(degradationProvenance());

    // Positive control for the degraded case below: on a current table the entry comes back whole.
    // Full entry equality, plus cardinality: an implementation that preserved the fingerprint
    // while losing the correlation, source, trust, class, channel or timestamp — or that wrote a
    // second fabricated row alongside — would otherwise pass.
    expect(evidenceRows())->toHaveCount(1)
        ->and($recorder->provenanceFor('invocation-356'))->toEqual([degradationProvenance()]);
});

it('records provenance correctly whichever migration is missing', function (string $migration): void {
    EvidenceTableSchema::createWithoutMigration($migration);

    $recorder = degradationRecorder();
    $recorder->recordProvenance(degradationProvenance());

    $absent = EvidenceTableSchema::absentColumns();

    if (in_array('content_fingerprint', $absent, true)) {
        // Without the fingerprint the entry cannot be represented at all, so nothing is written.
        expect(evidenceRows())->toHaveCount(0)
            ->and($recorder->provenanceFor('invocation-356'))->toBe([]);

        return;
    }

    // Every other lag: provenance is unaffected, whole entry and one row. An implementation that
    // dropped provenance writes whenever anything at all was missing would fail here.
    expect(evidenceRows())->toHaveCount(1)
        ->and($recorder->provenanceFor('invocation-356'))->toEqual([degradationProvenance()]);
})->with(EvidenceTableSchema::additiveMigrationNames());

it('returns no provenance rather than a fabricated entry when the fingerprint column is missing', function (): void {
    EvidenceTableSchema::createWithout(['content_fingerprint']);

    $recorder = degradationRecorder();
    $recorder->recordProvenance(degradationProvenance());

    // The defined outcome, not merely "it did not throw". A ProvenanceEntry requires a content
    // fingerprint, so a row written without one cannot be hydrated into a real record. Returning
    // an entry with an empty or invented fingerprint would be worse than returning nothing:
    // callers treat what comes back as evidence.
    // Zero rows, not merely an empty read: inserting a partial row and then declining to return
    // it would leave unreadable rows accumulating in the evidence table.
    expect(evidenceRows())->toHaveCount(0)
        ->and($recorder->provenanceFor('invocation-356'))->toBe([]);
});

it('inspects the column list once per instance rather than once per write', function (): void {
    EvidenceTableSchema::createWithout(['intent_id']);

    $recorder = degradationRecorder();
    $recorder->record(degradationEvidence());

    $introspections = 0;
    DB::listen(function ($query) use (&$introspections): void {
        if (str_contains($query->sql, 'pragma_table_') || str_contains($query->sql, 'sqlite_master')) {
            $introspections++;
        }
    });

    foreach (range(1, 5) as $ignored) {
        $recorder->record(degradationEvidence());
    }

    // A schema query on every guarded decision is not acceptable on the decision path.
    expect($introspections)->toBe(0)
        ->and(evidenceRows())->toHaveCount(6);
});

it('keeps omitting a column added after the instance inspected the table', function (): void {
    EvidenceTableSchema::createWithout(['intent_id']);

    $recorder = degradationRecorder();
    $recorder->record(degradationEvidence());

    app(DatabaseManager::class)->connection()->getSchemaBuilder()
        ->table(verdictTable('evidence'), function ($table): void {
            $table->string('intent_id', 64)->nullable();
        });

    $recorder->record(degradationEvidence());

    // The stated contract, chosen rather than fallen into: the column list is inspected once per
    // instance and process restart is the invalidation boundary — the same contract the
    // approval_context memo already ships with, so operators have one rule rather than two. An
    // online migration therefore does not retroactively widen what a running worker records, and
    // the deploy step is "migrate, then restart".
    //
    // This test is where that contract is decided. The implementation must also state it in the
    // CHANGELOG entry, the way the approval_context memo's restart note was stated — a contract
    // that lives only in a test is one operators never read.
    // Asserted as a SET, never by row position. The evidence table's primary key is a random
    // `uuid`, and InnoDB clusters the table on it, so an unordered read returns MySQL and MariaDB
    // rows in UUID order rather than insertion order — while PostgreSQL's heap and SQLite's rowid
    // happen to return insertion order. Indexing positionally here therefore passed on two engines
    // and failed on two others roughly two runs in three, depending on which UUID sorted last. The
    // rows are identical apart from `intent_id`, so which rows carry it is the whole contract and
    // position says nothing. (Same root cause as #311 item 6: no monotonic column to order by.)
    $rows = evidenceRows();

    expect($rows)->toHaveCount(2)
        ->and($rows->whereNotNull('intent_id'))->toHaveCount(0);

    // And the other half of the contract, without which a permanent process-wide cache would
    // pass: a recorder built after the migration must see the column. Restart is the boundary,
    // so crossing it has to actually restore the evidence.
    degradationRecorder()->record(degradationEvidence());

    $rows = evidenceRows();

    expect($rows)->toHaveCount(3)
        ->and($rows->whereNotNull('intent_id')->pluck('intent_id')->all())
        ->toBe([str_repeat('1', 64)]);
});

it('does not silently succeed when the evidence table is missing entirely', function (): void {
    EvidenceTableSchema::drop();

    // The empty-column-list case, which is also what a failed inspection looks like: filtering the
    // payload against an empty list would make insert() a no-op and every decision would appear to
    // record while nothing was written. It has to surface. Exception rather than a specific class:
    // whether this is the driver's QueryException or a clearer error is the implementer's call.
    expect(fn () => degradationRecorder()->record(degradationEvidence()))
        ->toThrow(Exception::class);
});
