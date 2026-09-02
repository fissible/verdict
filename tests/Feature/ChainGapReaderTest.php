<?php

declare(strict_types=1);

use Fissible\Attest\Chain\ChainStore;
use Fissible\Verdict\Contracts\ChainGapReader;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\ChainGapSummary;
use Fissible\Verdict\Evidence\DatabaseChainGapReader;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\Tests\Support\FlakyChainStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    EvidenceTableSchema::createComplete();
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists('verdict_evidence_alt');
});

/**
 * Write one chain_gap marker into $table with the exact shape AttestEvidenceRecorder::recordGap()
 * persists: record_type 'chain_gap', disposition 'gap', identity inside the reason JSON's `chain`.
 */
function insertGapInto(string $table, string $chainId, ?DateTimeImmutable $at = null): void
{
    app(DatabaseManager::class)->connection()->table($table)->insert([
        'id' => Str::uuid()->toString(),
        'record_type' => 'chain_gap',
        'correlation_id' => 'corr-'.Str::random(6),
        'stage' => 'decision',
        'disposition' => 'gap',
        'reason' => json_encode(['chain' => $chainId, 'phase' => 'append', 'attempts' => 3, 'error' => 'boom'], JSON_THROW_ON_ERROR),
        'recorded_at' => $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);
}

function insertGap(string $chainId, ?DateTimeImmutable $at = null): void
{
    insertGapInto(verdictTable('evidence'), $chainId, $at);
}

function gapReaderOn(string $table): ChainGapReader
{
    return new DatabaseChainGapReader(app(DatabaseManager::class)->connection(), $table);
}

function gapReader(): ChainGapReader
{
    return gapReaderOn(verdictTable('evidence'));
}

/** A recorder whose attest chain always fails to append, so record() exhausts and writes a gap. */
function makeGapRecorder(ChainStore $store): AttestEvidenceRecorder
{
    return new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        baseDelayMs: 1,
    );
}

function gapDecision(string $envelopeId): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: $envelopeId, capability: 'orders.refund', stage: 'authorization', disposition: 'permit',
        reason: null, argumentFingerprint: hash('sha256', 'args'), idempotencyKey: null,
        approvalReceiptFingerprint: null, reviewRequestFingerprint: null, approvalPhase: null,
        approvalOutcome: null, targetPolicy: null, targetStrategy: null,
        proposalTargetIdentityFingerprint: null, executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null, rateLimitKeyFingerprint: null, rateLimitPolicy: null,
        rateLimitLimit: null, rateLimitRemaining: null, rateLimitResetAt: null,
        executionClaimFingerprint: null, executionClaimBindingFingerprint: null,
        executionClaimPolicy: null, executionClaimStatus: null, executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable,
    );
}

it('reports no gaps for a chain that has none', function (): void {
    $summary = gapReader()->gapsForChain('verdict');

    expect($summary)->toBeInstanceOf(ChainGapSummary::class)
        ->and($summary->persistedCount)->toBe(0)
        ->and($summary->latestMarkAt)->toBeNull()
        ->and($summary->hasGaps())->toBeFalse();
});

it('counts only the marks belonging to the requested chain', function (): void {
    insertGap('verdict');
    insertGap('verdict');
    insertGap('tenant:7');

    // The chain filter is the whole point: without it this reads 3.
    expect(gapReader()->gapsForChain('verdict')->persistedCount)->toBe(2)
        ->and(gapReader()->gapsForChain('tenant:7')->persistedCount)->toBe(1)
        ->and(gapReader()->gapsForChain('absent')->persistedCount)->toBe(0);
});

it('reports hasGaps and the latest mark instant for the chain, in UTC', function (): void {
    $older = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $newer = new DateTimeImmutable('2026-01-02 12:30:00', new DateTimeZone('UTC'));

    insertGap('verdict', $older);
    insertGap('verdict', $newer);
    insertGap('other', new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'))); // newer, wrong chain

    $summary = gapReader()->gapsForChain('verdict');

    expect($summary->hasGaps())->toBeTrue()
        ->and($summary->persistedCount)->toBe(2)
        ->and($summary->latestMarkAt)->not->toBeNull()
        ->and($summary->latestMarkAt->getTimestamp())->toBe($newer->getTimestamp())
        ->and($summary->latestMarkAt->getTimezone()->getName())->toBe('UTC');
});

it('matches the chain id exactly — no whitespace or case folding', function (): void {
    insertGap('tenant:7');

    // Opaque identities. Collapsing these would silently merge distinct chains, and SQLite's
    // lenient comparison would hide a MySQL/MariaDB collation mistake here.
    expect(gapReader()->gapsForChain('tenant:7')->persistedCount)->toBe(1)
        ->and(gapReader()->gapsForChain('tenant:7 ')->persistedCount)->toBe(0)
        ->and(gapReader()->gapsForChain('TENANT:7')->persistedCount)->toBe(0);
});

it('ignores rows that are not chain_gap markers even with disposition gap and the chain in reason', function (): void {
    insertGap('verdict');

    // Decoy: differs from a mark ONLY in record_type. An implementation keying off disposition='gap'
    // (or off the reason JSON alone) would wrongly count this.
    app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->insert([
        'id' => Str::uuid()->toString(),
        'record_type' => 'decision',
        'stage' => 'authorization',
        'disposition' => 'gap',
        'reason' => json_encode(['chain' => 'verdict'], JSON_THROW_ON_ERROR),
        'recorded_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);

    expect(gapReader()->gapsForChain('verdict')->persistedCount)->toBe(1);
});

it('skips malformed or chain-less reasons without throwing, and reports none when only those exist', function (): void {
    $conn = app(DatabaseManager::class)->connection();
    foreach (['not json at all', json_encode(['phase' => 'append'], JSON_THROW_ON_ERROR)] as $reason) {
        $conn->table(verdictTable('evidence'))->insert([
            'id' => Str::uuid()->toString(), 'record_type' => 'chain_gap', 'stage' => 'decision',
            'disposition' => 'gap', 'reason' => $reason,
            'recorded_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ]);
    }

    // A chain whose only chain_gap rows are unreadable reports none — not a crash, not a phantom count.
    $summary = gapReader()->gapsForChain('verdict');
    expect($summary->persistedCount)->toBe(0)
        ->and($summary->hasGaps())->toBeFalse();
});

it('does not mutate the evidence table when read', function (): void {
    insertGap('verdict');
    insertGap('verdict');
    $conn = app(DatabaseManager::class)->connection();
    $before = $conn->table(verdictTable('evidence'))->count();

    gapReader()->gapsForChain('verdict');

    expect($conn->table(verdictTable('evidence'))->count())->toBe($before);
});

it('counts every persisted mark, past any default page size', function (): void {
    for ($i = 0; $i < 150; $i++) {
        insertGap('verdict');
    }

    // Guards against an accidental limit()/paginated aggregate quietly capping the floor.
    expect(gapReader()->gapsForChain('verdict')->persistedCount)->toBe(150);
});

it('reads back exactly one gap the real recorder wrote on the append-exhaustion path', function (): void {
    // The seam's promise, against the normal real-chain failure: a chain whose appends never land.
    $recorder = makeGapRecorder(new FlakyChainStore(AttestFixture::store(), failures: 99));

    $recorder->record(gapDecision('rt-append'));

    $summary = gapReader()->gapsForChain('verdict');

    // Exactly one — >=1 would let an overcounting reader (or a duplicate write) pass.
    expect($summary->persistedCount)->toBe(1)
        ->and($summary->hasGaps())->toBeTrue()
        ->and($summary->latestMarkAt)->not->toBeNull();
});

/** The minimal column set recordGap() writes — enough for the reader, which only reads
 *  record_type, reason and recorded_at. Used to stand up a distinct fallback table/connection. */
function createGapTable(Builder $schema, string $name): void
{
    $schema->create($name, function ($table): void {
        $table->string('id', 36)->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
        $table->string('stage', 32);
        $table->string('disposition', 32);
        $table->text('reason')->nullable();
        $table->timestamp('recorded_at');
    });
}

it('resolves from the container reading the attest fallback TABLE, not verdict.evidence.table', function (): void {
    // recordGap writes to verdict.evidence.attest.fallback_table, which a deployment may point away from
    // verdict.evidence.table. The container-bound reader must follow the gaps there.
    createGapTable(app(DatabaseManager::class)->connection()->getSchemaBuilder(), 'verdict_evidence_alt');
    config()->set('verdict.evidence.attest.fallback_table', 'verdict_evidence_alt');

    insertGapInto('verdict_evidence_alt', 'fb-chain');
    insertGap('only-in-default'); // decoy in the DEFAULT evidence table the reader must NOT see

    $reader = app(ChainGapReader::class);

    expect($reader->gapsForChain('fb-chain')->persistedCount)->toBe(1)          // read the fallback table
        ->and($reader->gapsForChain('only-in-default')->persistedCount)->toBe(0); // never read verdict.evidence.table
});

it('resolves from the container reading the attest fallback CONNECTION, not the default one', function (): void {
    // The gap source is a (connection, table) pair. A deployment may keep evidence on a dedicated
    // fallback database; the reader must read that connection, not the app's default.
    config()->set('database.connections.attest_fallback', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]);
    $alt = app(DatabaseManager::class)->connection('attest_fallback');
    createGapTable($alt->getSchemaBuilder(), verdictTable('evidence'));
    $alt->table(verdictTable('evidence'))->insert([
        'id' => Str::uuid()->toString(), 'record_type' => 'chain_gap', 'stage' => 'decision',
        'disposition' => 'gap',
        'reason' => json_encode(['chain' => 'fb-conn', 'phase' => 'append', 'attempts' => 3], JSON_THROW_ON_ERROR),
        'recorded_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ]);

    insertGap('only-in-default'); // decoy on the DEFAULT connection

    config()->set('verdict.evidence.attest.fallback_connection', 'attest_fallback');

    $reader = app(ChainGapReader::class);

    expect($reader->gapsForChain('fb-conn')->persistedCount)->toBe(1)           // read the named connection
        ->and($reader->gapsForChain('only-in-default')->persistedCount)->toBe(0); // not the default connection
});
