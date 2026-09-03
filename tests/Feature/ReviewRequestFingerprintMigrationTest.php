<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/**
 * #466: `review_request_fingerprint` was added to the evidence *create* migration and shipped with
 * no `add_*` migration beside it. Fresh v0.15.0 installs got the column. Every install whose
 * evidence table was created before v0.15.0 did not — and had no published migration that could
 * give it one, so `verdict:validate` reported a missing column the operator could not clear, while
 * the recorder's column-degradation path silently dropped the review-request correlation from every
 * durable decision record on the way in.
 *
 * The create migration is not a schema summary. It runs once per install and never again, so a
 * column added to it reaches only installs created after the edit. That is the invariant the first
 * test below pins, on the whole column list rather than on this one column: the next in-place edit
 * has to fail here rather than in someone's upgrade.
 *
 * The migration is written to tolerate the column already existing, because v0.15.0 is published:
 * an install created under it already has the column from the create migration, and would otherwise
 * meet a duplicate-column failure the moment it upgraded.
 */

/**
 * The evidence create migration's columns as every published release created them — verified
 * against the v0.1.0 through v0.15.0 tags, across which this list never changed. It is a historical
 * record of what is already in the field, not a description of the current schema.
 *
 * DO NOT append to this list. Appending here is precisely the edit that caused #466: it makes CI
 * green while leaving every install created before the edit without the column, and with no
 * migration able to give it one. A new evidence column belongs in an `add_*` migration, which is
 * the only kind an install that already ran the create migration will ever run. This list changes
 * only when a released create migration's own output changes, which it never has.
 */
const V0_15_0_EVIDENCE_CREATE_COLUMNS = [
    'id',
    'record_type',
    'correlation_id',
    'capability',
    'stage',
    'disposition',
    'reason',
    'source',
    'destination',
    'trust_zone',
    'trust',
    'data_class',
    'argument_fingerprint',
    'idempotency_key_fingerprint',
    'approval_receipt_fingerprint',
    'approval_phase',
    'approval_outcome',
    'target_policy',
    'target_strategy',
    'proposal_target_identity_fingerprint',
    'execution_target_identity_fingerprint',
    'target_identity_matched',
    'rate_limit_key_fingerprint',
    'rate_limit_policy',
    'rate_limit_limit',
    'rate_limit_remaining',
    'rate_limit_reset_at',
    'execution_claim_fingerprint',
    'execution_claim_binding_fingerprint',
    'execution_claim_policy',
    'execution_claim_status',
    'execution_claim_attempt',
    'requested_path_fingerprints',
    'released_path_fingerprints',
    'transform_fingerprints',
    'transformed_path_fingerprints',
    'transformation_count',
    'payload_fingerprint',
    'recorded_at',
];

const REVIEW_REQUEST_FINGERPRINT_MIGRATION = 'add_review_request_fingerprint_to_verdict_evidence_table';

function reviewRequestFingerprintStub(): Migration
{
    $stub = require dirname(__DIR__, 2).'/database/migrations/'.REVIEW_REQUEST_FINGERPRINT_MIGRATION.'.php.stub';

    expect($stub)->toBeInstanceOf(Migration::class);

    return $stub;
}

function evidenceCreateStub(): Migration
{
    $stub = require dirname(__DIR__, 2).'/database/migrations/create_verdict_evidence_table.php.stub';

    expect($stub)->toBeInstanceOf(Migration::class);

    return $stub;
}

/** @return list<string> */
function evidenceColumnListing(): array
{
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);

    /** @var list<string> $columns */
    $columns = $manager->connection()->getSchemaBuilder()->getColumnListing(verdictTable('evidence'));

    sort($columns);

    return $columns;
}

/** The one legacy row every upgrade case below writes before migrating, so data loss is visible. */
function insertPreUpgradeEvidenceRow(): void
{
    app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->insert([
        'id' => '019894b2-7af0-7000-8000-0000000004aa',
        'record_type' => 'decision',
        'correlation_id' => 'invocation-before-review-request-fingerprint',
        'stage' => 'proposal',
        'disposition' => 'deny',
        'transformation_count' => 0,
        'recorded_at' => '2026-08-01 12:00:00',
    ]);
}

afterEach(function (): void {
    EvidenceTableSchema::dropDerivations();
    EvidenceTableSchema::drop();
});

it('creates the evidence table with exactly the columns every published release created it with', function (): void {
    EvidenceTableSchema::drop();
    evidenceCreateStub()->up();

    $frozen = V0_15_0_EVIDENCE_CREATE_COLUMNS;
    sort($frozen);

    // Equality, not containment. A column added here is invisible to every install that already
    // ran this migration, and containment would let exactly that edit through (#466).
    expect(evidenceColumnListing())->toBe($frozen);
});

it('carries the review-request fingerprint column in an add migration, so an existing install can run it', function (): void {
    EvidenceTableSchema::drop();
    evidenceCreateStub()->up();

    // The premise of the whole ticket: the create migration alone leaves the column absent.
    expect(evidenceColumnListing())->not->toContain('review_request_fingerprint');

    reviewRequestFingerprintStub()->up();

    expect(evidenceColumnListing())->toContain('review_request_fingerprint');
});

it('adds the column to a populated pre-v0.15 table without disturbing the rows already in it', function (): void {
    EvidenceTableSchema::createWithoutMigration(REVIEW_REQUEST_FINGERPRINT_MIGRATION);
    insertPreUpgradeEvidenceRow();

    reviewRequestFingerprintStub()->up();

    $connection = app(DatabaseManager::class)->connection();
    $row = (array) $connection->table(verdictTable('evidence'))->first();

    // The retained record survives the upgrade whole, and the new column reads null on it rather
    // than a fabricated value: nothing knows what review request a pre-upgrade row belonged to.
    expect($connection->table(verdictTable('evidence'))->count())->toBe(1)
        ->and($row['correlation_id'])->toBe('invocation-before-review-request-fingerprint')
        ->and($row['disposition'])->toBe('deny')
        ->and($row['review_request_fingerprint'])->toBeNull();
});

it('is a no-op against a table created by v0.15.0, which already has the column', function (): void {
    // v0.15.0's create migration produced this column itself, so an install created under it meets
    // the new migration with the column already present. It has to survive that, not fail on it.
    EvidenceTableSchema::createComplete();
    insertPreUpgradeEvidenceRow();

    $before = evidenceColumnListing();

    reviewRequestFingerprintStub()->up();

    $connection = app(DatabaseManager::class)->connection();

    expect(evidenceColumnListing())->toBe($before)
        ->and($connection->table(verdictTable('evidence'))->count())->toBe(1)
        ->and($connection->table(verdictTable('evidence'))->value('correlation_id'))
        ->toBe('invocation-before-review-request-fingerprint');
});

it('leaves the column in place on rollback, because it may not be the migration that added it', function (): void {
    // Measured before anything is written: completeColumnListing() rebuilds the table to measure
    // it, so asking afterwards would wipe the row this test exists to protect.
    $complete = EvidenceTableSchema::completeColumnListing();
    sort($complete);

    EvidenceTableSchema::createComplete();
    insertPreUpgradeEvidenceRow();
    app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))
        ->update(['review_request_fingerprint' => str_repeat('4', 64)]);

    reviewRequestFingerprintStub()->down();

    $connection = app(DatabaseManager::class)->connection();

    // The one asymmetry in this migration, and it is deliberate. Rolling it back means downgrading
    // to a release whose own create migration declares this column, and on an install created under
    // v0.15.0 the column is that create migration's, not this one's — dropping it would destroy
    // retained evidence the downgraded release still reads and writes. The migration cannot tell
    // the two provenances apart, so it takes the branch that loses nothing: an unused nullable
    // column costs an install nothing, and a dropped one costs it every review correlation it held.
    expect(evidenceColumnListing())->toBe($complete)
        ->and($connection->table(verdictTable('evidence'))->count())->toBe(1)
        ->and($connection->table(verdictTable('evidence'))->value('review_request_fingerprint'))
        ->toBe(str_repeat('4', 64));
});

it('leaves a v0.15.0-created column in place across a full up-then-down cycle', function (): void {
    // The provenance the migration cannot see: this table's column came from v0.15.0's create
    // migration, so up() adds nothing and down() has nothing of its own to remove. A down() written
    // as an unconditional drop passes every other test here and silently fails this one.
    $complete = EvidenceTableSchema::completeColumnListing();
    sort($complete);

    EvidenceTableSchema::createComplete();
    insertPreUpgradeEvidenceRow();

    reviewRequestFingerprintStub()->up();
    reviewRequestFingerprintStub()->down();

    expect(evidenceColumnListing())->toBe($complete)
        ->and(app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->count())->toBe(1);
});

it('gives the column the same storage shape as the fingerprint columns beside it', function (): void {
    EvidenceTableSchema::drop();
    evidenceCreateStub()->up();
    reviewRequestFingerprintStub()->up();

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);

    $columns = collect($manager->connection()->getSchemaBuilder()->getColumns(verdictTable('evidence')))
        ->keyBy('name');

    /** @var array<string, mixed>|null $added */
    $added = $columns->get('review_request_fingerprint');
    /** @var array<string, mixed> $sibling */
    $sibling = $columns->get('approval_receipt_fingerprint');

    // Compared against the sibling rather than asserted as a literal type name, because every
    // engine in the matrix reports its own: SQLite calls a char(64) a varchar, so a `string()`
    // column would be indistinguishable from a `char(64)` there. Measured against the column it
    // mirrors, a widened or renamed type fails on MySQL and PostgreSQL where it is visible.
    expect($added)->not->toBeNull()
        ->and($added['type'])->toBe($sibling['type'])
        ->and($added['type_name'])->toBe($sibling['type_name'])
        ->and($added['nullable'])->toBeTrue()
        ->and($added['default'])->toBeNull();
});

it('publishes the migration to a filename that runs after the create migration it upgrades', function (): void {
    $migrations = ServiceProvider::pathsToPublish(VerdictServiceProvider::class, 'verdict-evidence-migrations');

    $destinations = [];

    foreach ($migrations as $stub => $destination) {
        $destinations[basename($stub, '.php.stub')] = basename($destination, '.php');
    }

    // Laravel runs migrations in filename order, so a published timestamp earlier than the create
    // migration's would ALTER a table that does not exist yet. Every direct-stub test here would
    // still pass; only a real vendor:publish followed by migrate would fail.
    expect($destinations)->toHaveKey(REVIEW_REQUEST_FINGERPRINT_MIGRATION)
        ->and($destinations[REVIEW_REQUEST_FINGERPRINT_MIGRATION])
        ->toEndWith('_'.REVIEW_REQUEST_FINGERPRINT_MIGRATION)
        ->and($destinations[REVIEW_REQUEST_FINGERPRINT_MIGRATION])
        ->toBeGreaterThan($destinations['create_verdict_evidence_table']);
});

it('writes the column into the table the deployment configured rather than the default name', function (): void {
    config()->set('verdict.evidence.table', 'renamed_evidence_466');

    EvidenceTableSchema::drop('verdict_evidence');
    EvidenceTableSchema::drop();
    evidenceCreateStub()->up();
    reviewRequestFingerprintStub()->up();

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $schema = $manager->connection()->getSchemaBuilder();

    expect($schema->hasColumn('renamed_evidence_466', 'review_request_fingerprint'))->toBeTrue()
        ->and($schema->hasTable('verdict_evidence'))->toBeFalse();

    $schema->dropIfExists('renamed_evidence_466');
});

it('reports the unclearable advisory on an install that never ran the migration', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createWithoutMigration(REVIEW_REQUEST_FINGERPRINT_MIGRATION);
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // The exact symptom the ticket opens with, reproduced before the fix is applied.
    expect($output)->toContain('review_request_fingerprint')
        ->and($exitCode)->toBe(1);
});

it('clears the advisory once the published migrations have all been run', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // The acceptance criterion: an upgrading install that publishes and runs Verdict's migrations
    // has a way to make the error go away.
    expect($output)->not->toContain('review_request_fingerprint')
        ->and($exitCode)->toBe(0);
});

it('records the review-request fingerprint once the migration has run', function (): void {
    EvidenceTableSchema::createComplete();

    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
    $recorder->record(new DecisionEvidence(
        envelopeId: 'envelope-466',
        capability: 'orders.cancel',
        stage: 'review',
        disposition: 'deny',
        reason: 'Review pending.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'tool-call-466',
        approvalReceiptFingerprint: null,
        reviewRequestFingerprint: str_repeat('4', 64),
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
        recordedAt: new DateTimeImmutable('2026-09-02 09:00:00', new DateTimeZone('UTC')),
    ));

    // The column is not decoration: the migration exists so this write survives. Without it the
    // recorder degrades and the correlation is gone from the durable record.
    expect(app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->value('review_request_fingerprint'))
        ->toBe(str_repeat('4', 64));
});
