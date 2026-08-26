<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\CapabilityConfiguration;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Evidence\RecordDigest;
use Fissible\Verdict\Support\SystemClock;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\FlakyChainStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

/**
 * Verdict stamps evidence with PHP's *default* timezone but reads it back as UTC (#335). Laravel
 * sets that default from `app.timezone` at boot, so on any host that is not UTC every evidence row
 * is written app-local and read back shifted by the host's offset — and the record digest, which is
 * computed over a UTC-normalised instant, can no longer be re-derived from the row Verdict wrote.
 *
 * These tests pin the *behaviour* — what lands in the column and what comes back — not the fix.
 * Injecting `Clock` and hard-coding `new DateTimeZone('UTC')` at each site are both acceptable
 * implementations; nothing here can tell them apart.
 *
 * `America/Chicago` is deliberate: a fixed −5/−6 offset makes an off-by-a-zone failure unmistakable
 * (a UTC host would pass these by accident, which is exactly why the bug survived this long).
 *
 * @verdict-claim evidence.timestamps-utc
 */
const ZONE_UNDER_TEST = 'America/Chicago';

beforeEach(function (): void {
    $this->originalZone = date_default_timezone_get();

    $connection = app(DatabaseManager::class)->connection();
    $schema = $connection->getSchemaBuilder();

    foreach ([verdictTable('evidence'), verdictTable('derivations'), verdictTable('capability_configurations')] as $table) {
        $schema->dropIfExists($table);
    }

    foreach ([
        'create_verdict_evidence_table',
        'add_provenance_to_verdict_evidence_table',
        'add_invocation_id_to_verdict_evidence_table',
        'create_verdict_provenance_derivations_table',
        'add_tool_kind_to_verdict_evidence_table',
        'add_configuration_fingerprint_to_verdict_evidence_table',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table',
        'add_target_source_to_verdict_evidence_table',
        'add_tool_description_fingerprints_to_verdict_evidence_table',
        'add_record_identity_to_verdict_evidence_table',
        'add_intent_id_to_verdict_evidence_table',
        'create_verdict_capability_configurations_table',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub.'.php.stub')->up();
    }

    // Simulate a non-UTC host exactly as Laravel produces one: app.timezone drives PHP's default.
    date_default_timezone_set(ZONE_UNDER_TEST);
});

afterEach(function (): void {
    date_default_timezone_set($this->originalZone);

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    foreach ([verdictTable('evidence'), verdictTable('derivations'), verdictTable('capability_configurations')] as $table) {
        $schema->dropIfExists($table);
    }
});

function zoneTestEvaluation(): Evaluation
{
    return new Evaluation(
        envelope: ActionEnvelope::wrap(
            new ActionProposal('orders.refund', ['order_id' => 7001]),
            new ActionContext('customer-72'),
        ),
        capability: null,
        target: null,
        decision: Decision::permit('Permitted.'),
        stage: EvaluationStage::Proposal,
    );
}

/** The instant a UTC-correct implementation must have stamped, as a closed window. */
function utcWindow(callable $act): array
{
    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $result = $act();
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return [$result, $before, $after];
}

function storedRecordedAt(string $table = 'evidence'): DateTimeImmutable
{
    $row = app(DatabaseManager::class)->connection()->table(verdictTable($table))->first();

    // Read exactly as Verdict's own reader does — DatabaseEvidenceRecorder parses this column with
    // an explicit UTC zone, so that is the meaning the stored string is obliged to carry.
    return new DateTimeImmutable((string) $row->recorded_at, new DateTimeZone('UTC'));
}

it('stores a decision evidence timestamp as UTC on a non-UTC host', function (): void {
    [$evidence, $before, $after] = utcWindow(fn (): DecisionEvidence => DecisionEvidence::fromEvaluation(zoneTestEvaluation()));

    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);

    $stored = storedRecordedAt();

    expect($stored->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($stored->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});

it('keeps the record digest re-derivable from the row it wrote', function (): void {
    // docs/evidence-record-identity.md promises the digest can be recomputed offline from the
    // stored record. The digest normalises to UTC; the column does not — so on a non-UTC host the
    // row and its own digest describe instants five hours apart, and that promise is false.
    $evidence = DecisionEvidence::fromEvaluation(zoneTestEvaluation());

    (new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection()))->record($evidence);

    $digestedInstant = RecordDigest::stableFields($evidence)['recorded_at'];

    expect(storedRecordedAt()->format('Y-m-d\TH:i:s\Z'))->toBe($digestedInstant);
});

it('round-trips a provenance entry through the reader on a non-UTC host', function (): void {
    // The positive control: ProvenanceLedger already stamps through Clock (SystemClock is UTC), so
    // this round trip must hold TODAY. If it ever fails, the harness is wrong rather than the
    // write path — without it, a green suite could mean the mechanism never observed anything.
    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
    $ledger = new ProvenanceLedger($recorder, $recorder, new SystemClock);

    [$entry, $before, $after] = utcWindow(fn () => $ledger->record(
        correlationId: 'inv-zone-1',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: 'a retrieved note',
    ));

    $readBack = $recorder->provenanceFor('inv-zone-1')[0];

    expect($readBack->recordedAt->getTimestamp())->toBe($entry->recordedAt->getTimestamp())
        ->and($readBack->recordedAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($readBack->recordedAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});

/*
 * `ActionEnvelope::$createdAt` is deliberately NOT tested here, and that is a finding rather than a
 * gap. #335 lists it "for completeness", but it is never persisted — nothing in `src/` reads it into
 * a column — so `new DateTimeImmutable` already denotes the correct instant and `getTimestamp()` is
 * right today. The only assertion that could fail was `format('T') === 'UTC'`, which pins the *fix*
 * (Clock injection returns UTC-zoned objects; converting at a persistence boundary need not) rather
 * than any observable behaviour. A test nobody can break without changing an unobservable detail is
 * a test that will one day be "fixed" by deleting the detail. Changing that site remains harmless
 * and consistent; it is simply not something these tests can honestly hold anyone to.
 */

it('stores an attest chain-gap marker recorded_at as UTC on a non-UTC host', function (): void {
    // The gap row is the one evidence record written *because* something already failed, so it is
    // the row an operator reads while reconstructing an incident — the worst one to have shifted.
    $chain = new FlakyChainStore(AttestFixture::store(), failures: 99);

    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($chain),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: 'alert',
        baseDelayMs: 1,
    );

    [, $before, $after] = utcWindow(function () use ($recorder) {
        $recorder->record(DecisionEvidence::fromEvaluation(zoneTestEvaluation()));

        return null;
    });

    $row = app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))
        ->where('record_type', 'chain_gap')->first();
    $reason = json_decode((string) $row->reason, true, flags: JSON_THROW_ON_ERROR);

    // Prove this row came from retry exhaustion before trusting its timestamp: an eagerly written
    // or malformed gap row would otherwise satisfy the assertion below without the path under test
    // ever running, and the timestamp check would be green for the wrong reason.
    expect($chain->counter()->appends)->toBe(3)
        ->and($row->stage)->toBe('decision')
        ->and($row->disposition)->toBe('gap')
        ->and($reason['phase'])->toBe('append')
        ->and($reason['attempts'])->toBe(3);

    $stored = new DateTimeImmutable((string) $row->recorded_at, new DateTimeZone('UTC'));

    expect($stored->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($stored->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});

it('stores a capability configuration first_seen_at as UTC on a non-UTC host', function (): void {
    $store = new DatabaseCapabilityConfigurationStore(app(DatabaseManager::class)->connection());

    [, $before, $after] = utcWindow(function () use ($store) {
        $store->record(new CapabilityConfiguration(
            fingerprint: hash('sha256', 'zone-config'),
            capability: 'orders.refund',
            declared: ['name' => 'orders.refund'],
        ));

        return null;
    });

    $row = app(DatabaseManager::class)->connection()->table(verdictTable('capability_configurations'))->first();
    $stored = new DateTimeImmutable((string) $row->first_seen_at, new DateTimeZone('UTC'));

    expect($stored->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($stored->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});
