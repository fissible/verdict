<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Support\SystemClock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * #362 — a distinct lever from #335. The store read paths hydrate every stored timestamp with
 * `new DateTimeImmutable((string) $row->..., new DateTimeZone('UTC'))` — they LABEL the driver's
 * returned string UTC. #335 proved that safe for the PHP application timezone (`app.timezone`,
 * driven with `date_default_timezone_set`). This probe tests the other lever: a non-UTC DATABASE
 * CONNECTION timezone (Laravel's per-connection `timezone` option), which sets the DB session time
 * zone and, on MySQL, governs `TIMESTAMP` column conversion on read and write.
 *
 * It answers one empirical question: does Verdict's own write -> read round-trip preserve the
 * intended UTC instant when the connection runs a non-UTC session zone? A `+05:30` offset is
 * deliberate — a whole-zone shift lands ~5.5h outside the UTC window, unmistakably, and a half-hour
 * offset cannot be confused with any boundary rounding.
 *
 * SCOPE: this exercises Verdict's OWN write and read only. A green result means MySQL's write and
 * read conversions cancel under the same session zone — it does NOT prove UTC correctness for raw
 * SQL against the column, a differently-zoned reader, offline digest reconstruction, or a session
 * zone changed between write and read. Those remain the documented boundary.
 *
 * MySQL/MariaDB only: SQLite has no session time zone, and the Postgres `timestamp` type Verdict's
 * migrations create is not session-converted.
 */
const CONNECTION_ZONE = '+05:30';

beforeEach(function (): void {
    $this->connectionName = config('database.default');

    if (! in_array(app(DatabaseManager::class)->connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Connection-timezone conversion is a MySQL/MariaDB behaviour; SQLite has no session zone and Postgres `timestamp` is not session-converted.');
    }

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists(verdictTable('derivations'));

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
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub.'.php.stub')->up();
    }

    // Configure a non-UTC connection timezone and force a fresh connection so MySQL issues
    // `SET time_zone` for the new session. Capture the prior setting to restore it afterwards.
    $this->originalTimezone = config('database.connections.'.$this->connectionName.'.timezone');
    config(['database.connections.'.$this->connectionName.'.timezone' => CONNECTION_ZONE]);
    DB::purge($this->connectionName);

    // Sentinel: setup completed (past the skip). A boolean flag, not isset() on originalTimezone —
    // that value is legitimately null for a connection with no timezone config, and isset(null) is
    // false, which would leak the +05:30 override past teardown.
    $this->cleanupNeeded = true;
});

afterEach(function (): void {
    // Nothing to clean up when the driver gate skipped before any setup ran.
    if (! ($this->cleanupNeeded ?? false)) {
        return;
    }

    config(['database.connections.'.$this->connectionName.'.timezone' => $this->originalTimezone]);
    DB::purge($this->connectionName);

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists(verdictTable('derivations'));
});

/** Assert — inside the test that relies on it — that the live session zone really is non-UTC. */
function assertNonUtcSession(): void
{
    $tz = app(DatabaseManager::class)->connection()->selectOne('SELECT @@session.time_zone AS tz')->tz;

    // Must be the requested fixed offset — not `SYSTEM`, not a named zone that might resolve to UTC.
    expect($tz)->toBe(CONNECTION_ZONE);
}

it('confirms the connection runs a non-UTC session time zone', function (): void {
    assertNonUtcSession();
});

it('round-trips a provenance timestamp through the real reader to the correct UTC instant under a non-UTC connection timezone', function (): void {
    // Premise, proven in this test's own fresh session (not a sibling test's): the session is non-UTC.
    assertNonUtcSession();

    $recorder = new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
    $ledger = new ProvenanceLedger($recorder, $recorder, new SystemClock);

    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $entry = $ledger->record(
        correlationId: 'inv-conn-zone-1',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: 'a retrieved note',
    );
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // The production read path: DatabaseEvidenceRecorder::provenanceFor() hydrates recorded_at.
    $readBack = $recorder->provenanceFor('inv-conn-zone-1')[0];

    // The write's intended instant must survive the read unchanged...
    expect($readBack->recordedAt->getTimestamp())->toBe($entry->recordedAt->getTimestamp())
        // ...and land inside the real UTC window — a +05:30 shift would put it ~5.5h outside.
        ->and($readBack->recordedAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
        ->and($readBack->recordedAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
});
