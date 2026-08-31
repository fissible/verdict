<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Console\SessionTimezoneAudit;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Evidence\ApprovalOperationEvidence;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\Intents\DatabaseActionIntentStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;

/**
 * A non-database stand-in for both narrow evidence roles. Defined here rather than reused from
 * another Pest file: cross-file class references depend on suite load order, which Pest randomises.
 */
final class SessionTimezoneProbeWriter implements EvidenceWriter, ProvenanceLedgerStore
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordApprovalOperation(ApprovalOperationEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        return [];
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        return [];
    }
}

/**
 * #309: every Verdict table stores instants as `$table->timestamp()`, and six stores reinterpret
 * what comes back as UTC unconditionally. On MySQL and MariaDB that column type converts on write
 * AND on read using the session `time_zone`, so the round-trip is correct only because both ends
 * happen to agree.
 *
 * #362 already proved the agreement holds: a connection running `+05:30` round-trips correctly,
 * because MySQL's two conversions cancel. That test states its own boundary, and this issue is
 * exactly what it excluded — "a session zone changed between write and read", a second node on a
 * different zone, a DBA session, a migration worker. Nothing in Verdict asserts the agreement, so
 * nothing notices when it breaks. Approvals then outlive or predecease their TTL silently.
 *
 * WHY THIS IS A DEPLOY-TIME ASSERTION AND NOT A SCHEMA CHANGE. The hazard is 15 columns across 7
 * tables, not the 5 receipt columns the issue names, and six read paths — so a receipts-only
 * conversion would leave the identical defect on the audit trail and on at-most-once admission, and
 * leave two conventions in one schema. The repo-wide conversion is the permanent answer and is
 * filed as its own 1.0-boundary decision (#415). This is the guard, and one assertion covers every
 * column and every store at once.
 *
 * WHY `SYSTEM` IS AN ERROR. `@@session.time_zone` returns `SYSTEM` when nothing set it, meaning
 * "whatever the host is". That is not a property the deployment declared; it does not survive a
 * host change, an image bump, or a second node configured differently. Accepting it because the
 * host happens to be UTC today is the assumption this check exists to remove.
 */
/**
 * Runs $body with a complete evidence schema in place, then removes it.
 *
 * Without this the command exits 1 for missing evidence tables, and every exit-code assertion in
 * this file would be reading that instead of the timezone audit — the positive controls would stay
 * red after a correct implementation, and the negative ones would pass without the check existing.
 */
function sessionTimezoneWithSchema(callable $body): void
{
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    try {
        $body();
    } finally {
        EvidenceTableSchema::dropDerivations();
        EvidenceTableSchema::drop();
    }
}

/** Runs a published migration stub against whatever connection is currently the default. */
function sessionTimezoneMigrate(string $stub): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/'.$stub.'.php.stub';

    assert($migration instanceof Migration);

    $migration->up();
}

function sessionTimezoneRun(): array
{
    $exitCode = Artisan::call('verdict:validate');

    return [Artisan::output(), $exitCode];
}

function sessionTimezoneIsMysql(): bool
{
    return in_array(
        app(DatabaseManager::class)->connection()->getDriverName(),
        ['mysql', 'mariadb'],
        true,
    );
}

/** A second sqlite connection, so "every distinct connection" has something to distinguish. */
/**
 * Runs $probe with the named connection's session zone set to $zone, restoring whatever the zone
 * actually was afterwards.
 *
 * Restoring the captured value rather than forcing `+00:00` matters: the lane's connection may
 * arrive with any zone, and a probe that "restores" a value it never read is silently rewriting
 * the environment for every test after it.
 */
function sessionTimezoneWith(string $connection, string $zone, callable $probe): void
{
    $db = app(DatabaseManager::class)->connection($connection);
    $original = (string) $db->selectOne('select @@session.time_zone as tz')->tz;

    try {
        $db->statement("SET time_zone = '{$zone}'");
        $probe();
    } finally {
        $db->statement('SET time_zone = ?', [$original]);
    }
}

function sessionTimezoneSecondConnection(string $name): void
{
    config()->set("database.connections.{$name}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
}

// ---------------------------------------------------------------------------------------------
// The decision, pure. Exhaustive here because the engine that can produce a wrong value is not the
// engine this suite usually runs on — every other test in this file would otherwise be describing
// a rule nothing checks.
// ---------------------------------------------------------------------------------------------

it('accepts only an explicitly declared UTC session zone', function (string $zone, bool $rejected): void {
    expect(SessionTimezoneAudit::rejects('mysql', $zone))->toBe($rejected);
})->with([
    'the offset form' => ['+00:00', false],
    'the named form' => ['UTC', false],
    // Deliberately widened from the literal allowlist: MySQL compares named zones
    // case-insensitively, so `SET time_zone = 'utc'` produces a genuinely correct deployment and
    // rejecting it would fail a session that is already what the check wants. Case is the only
    // widening — `GMT` below is still rejected.
    'the named form in lower case' => ['utc', false],
    // The whole point of the check: an undeclared zone is not a UTC zone, however the host is set.
    'SYSTEM' => ['SYSTEM', true],
    'a half-hour offset' => ['+05:30', true],
    'a whole-hour offset' => ['-08:00', true],
    'a named non-UTC zone' => ['Europe/London', true],
    // GMT is a fixed-zero zone, so this is deliberately narrower than "is it actually UTC". The
    // supported configuration is the two spellings an operator can grep for and a reviewer can
    // confirm without knowing whether a named zone observes DST. Widening the accepted set is a
    // policy change, not a bug fix.
    'GMT' => ['GMT', true],
]);

it('does not reject anything on an engine that cannot reinterpret', function (string $driver): void {
    // SQLite has no session zone; PostgreSQL's `timestamp without time zone` stores the literal.
    // Checking them would report a problem a deployment cannot have and cannot fix.
    expect(SessionTimezoneAudit::rejects($driver, 'SYSTEM'))->toBeFalse()
        ->and(SessionTimezoneAudit::rejects($driver, '+05:30'))->toBeFalse();
})->with(['sqlite', 'pgsql']);

it('rejects a MariaDB session zone on the same terms as MySQL', function (): void {
    // Laravel 11 split MariaDB onto its own driver, and its grammar declares no typeTimestamp() —
    // it inherits MySQL's, so it inherits the conversion. A check that named only 'mysql' would
    // silently stop covering a MariaDB deployment the day it switched drivers.
    expect(SessionTimezoneAudit::rejects('mariadb', 'SYSTEM'))->toBeTrue()
        ->and(SessionTimezoneAudit::rejects('mariadb', '+00:00'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Which connections get audited.
// ---------------------------------------------------------------------------------------------

it('audits every distinct connection a database-backed store uses', function (): void {
    sessionTimezoneSecondConnection('approvals-conn');
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.connection', 'approvals-conn');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $names = array_keys(SessionTimezoneAudit::auditable(app()));

    // Two stores, two connections, two answers to this question. Auditing one and reporting on the
    // deployment proves nothing about the other — a receipt store on a node whose session zone
    // drifted is exactly the case this issue opens with.
    expect($names)->toContain('approvals-conn')
        ->and($names)->toContain((string) config('database.default'));
});

it('audits a connection once however many stores share it', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    // The ordinary deployment: everything on one connection. Six stores must not become six
    // identical errors, or the report is unreadable exactly when it matters.
    expect(SessionTimezoneAudit::auditable(app()))->toHaveCount(1);
});

it('does not audit a connection no database-backed store opens', function (): void {
    sessionTimezoneSecondConnection('unused-conn');
    config()->set('verdict.approvals.store', InMemoryApprovalReceiptStore::class);
    config()->set('verdict.approvals.connection', 'unused-conn');
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);
    // Evidence stays database-backed so the expected set is NOT empty. Asserting only the absence
    // of 'unused-conn' would be satisfied by an audit that found nothing at all.
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $names = array_keys(SessionTimezoneAudit::auditable(app()));

    // The lesson #395 settled, one audit over: a connection key belongs to a store that may not be
    // a database store at all. Reporting on a connection nothing opens sends an operator to fix a
    // deployment that is already correct.
    expect($names)->toContain((string) config('database.default'))
        ->and($names)->not->toContain('unused-conn');
});

it('audits the connection of every database-backed store, not just the ones #395 already knew about', function (string $storeKey, string $storeClass, string $connection): void {
    sessionTimezoneSecondConnection($connection);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set($storeKey.'.store', $storeClass);
    config()->set($storeKey.'.connection', $connection);

    // Each store owns a connection key of its own, and each writes at least one timestamp column.
    // A check that covered the two stores this issue's example happens to name would leave the
    // other four exactly as exposed, which is the mistake the issue itself made about columns.
    expect(array_keys(SessionTimezoneAudit::auditable(app())))->toContain($connection);
})->with([
    'rate limits' => ['verdict.rate_limits', DatabaseRateLimitStore::class, 'rl-conn'],
    'execution claims' => ['verdict.execution_claims', DatabaseExecutionClaimStore::class, 'claims-conn'],
    'action intents' => ['verdict.intents', DatabaseActionIntentStore::class, 'intents-conn'],
    'capability configurations' => ['verdict.capability_configurations', DatabaseCapabilityConfigurationStore::class, 'caps-conn'],
]);

it('audits the evidence connection through the effective writer or ledger role', function (string $role): void {
    sessionTimezoneSecondConnection('evidence-conn');
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.'.$role, DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.connection', 'evidence-conn');

    // #395's rule, inherited rather than restated: the evidence surface is reached through writer
    // and ledger independently, so a deployment whose recorder is Null but whose writer is the
    // database recorder still opens that connection and still depends on its session zone.
    expect(array_keys(SessionTimezoneAudit::auditable(app())))->toContain('evidence-conn');
})->with(['writer', 'ledger']);

it('does not audit the evidence connection when no effective evidence role is database-backed', function (): void {
    sessionTimezoneSecondConnection('evidence-conn');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', SessionTimezoneProbeWriter::class);
    config()->set('verdict.evidence.ledger', SessionTimezoneProbeWriter::class);
    config()->set('verdict.evidence.connection', 'evidence-conn');
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);

    // The converse #395 also settled: both narrow roles overridden means the legacy recorder value
    // is a leftover and that connection is opened by nothing.
    $names = array_keys(SessionTimezoneAudit::auditable(app()));

    expect($names)->toContain((string) config('database.default'))
        ->and($names)->not->toContain('evidence-conn');
});

it('audits the attest fallback connection', function (): void {
    sessionTimezoneSecondConnection('attest-conn');
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict-309');
    config()->set('verdict.evidence.attest.fallback_connection', 'attest-conn');

    // Attest serves provenance and derivations from a database fallback on its own connection —
    // two timestamp columns whose round-trip depends on that session zone, on the recorder chosen
    // specifically for evidence integrity.
    expect(array_keys(SessionTimezoneAudit::auditable(app())))->toContain('attest-conn');
});

// ---------------------------------------------------------------------------------------------
// End to end through the command.
// ---------------------------------------------------------------------------------------------

it('reports no session-timezone problem on an engine that cannot reinterpret', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    sessionTimezoneWithSchema(function (): void {
        [$output, $exitCode] = sessionTimezoneRun();

        // The control that keeps this check from firing everywhere. On the suite's usual engine
        // there is no session zone to be wrong, and a check that complained here would be turned
        // off. The exit code goes in too: silence plus a failing command is still a failing
        // deployment — which is why the schema has to be present for it to mean anything.
        expect($output)->not->toContain('session time zone')
            ->and($exitCode)->toBe(0);
    });
})->skip(fn (): bool => sessionTimezoneIsMysql(), 'Asserts the non-converting engines; MySQL has its own case.');

it('fails the deployment when a MySQL session zone is not declared UTC', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    sessionTimezoneWithSchema(fn () => sessionTimezoneWith((string) config('database.default'), '+05:30', function (): void {
        [$output, $exitCode] = sessionTimezoneRun();

        // The live assertion, and the only one that proves the check reads a real session rather
        // than a config value. It runs on the MySQL lane; everywhere else there is no zone to set.
        expect($output)->toContain('session time zone')
            ->and($output)->toContain('+05:30')
            ->and($exitCode)->toBe(1);
    }));
})->skip(fn (): bool => ! sessionTimezoneIsMysql(), 'Requires a MySQL/MariaDB session zone.');

it('passes when the MySQL session zone is declared UTC', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    sessionTimezoneWithSchema(fn () => sessionTimezoneWith((string) config('database.default'), '+00:00', function (): void {
        [$output, $exitCode] = sessionTimezoneRun();

        // The positive control on the engine that matters. Without it, a check that failed every
        // MySQL deployment unconditionally would satisfy the test above. The exit code is asserted
        // too: a check that stayed silent while still failing the command would pass on output
        // alone. It restores the captured zone like the others — it mutates a shared session.
        expect($output)->not->toContain('session time zone')
            ->and($exitCode)->toBe(0);
    }));
})->skip(fn (): bool => ! sessionTimezoneIsMysql(), 'Requires a MySQL/MariaDB session zone.');

it('fails on a badly zoned store connection that is not the default', function (): void {
    config()->set('database.connections.alt-mysql', config('database.connections.'.config('database.default')));
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.connection', 'alt-mysql');

    // A table of this probe's own, dropped in finally. Running the shipped stub under its default
    // name would leave a real `verdict_approval_receipts` behind on a database every later test in
    // the lane shares — the leak is invisible until something else asserts on that table.
    config()->set('verdict.approvals.table', 'verdict_309_receipts');

    $default = (string) config('database.default');
    config()->set('database.default', 'alt-mysql');
    sessionTimezoneMigrate('create_verdict_approval_receipts_table');
    config()->set('database.default', $default);

    try {
        sessionTimezoneWith('alt-mysql', '+05:30', function (): void {
            [$output, $exitCode] = sessionTimezoneRun();

            // The one the default-connection test cannot catch: an implementation querying only the
            // application default would report this deployment healthy while the receipt store —
            // the one whose TTLs this issue is about — runs on a shifted session.
            expect($output)->toContain('session time zone')
                ->and($output)->toContain('alt-mysql')
                ->and($exitCode)->toBe(1);
        });
    } finally {
        app(DatabaseManager::class)->connection('alt-mysql')
            ->getSchemaBuilder()
            ->dropIfExists('verdict_309_receipts');
        app(DatabaseManager::class)->purge('alt-mysql');
    }
})->skip(fn (): bool => ! sessionTimezoneIsMysql(), 'Requires a MySQL/MariaDB session zone.');

it('does not audit the attest fallback when no effective evidence role reaches it', function (): void {
    sessionTimezoneSecondConnection('attest-conn');
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict-309');
    config()->set('verdict.evidence.attest.fallback_connection', 'attest-conn');
    config()->set('verdict.evidence.writer', SessionTimezoneProbeWriter::class);
    config()->set('verdict.evidence.ledger', SessionTimezoneProbeWriter::class);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);

    // The converse of the fallback test, and the boundary that one cannot hold: with both narrow
    // roles overridden nothing resolves to Attest, so its fallback connection is opened by nothing
    // and reporting on it would send an operator to fix a session zone no Verdict store reads.
    $names = array_keys(SessionTimezoneAudit::auditable(app()));

    expect($names)->toContain((string) config('database.default'))
        ->and($names)->not->toContain('attest-conn');
});

it('fails a MySQL deployment whose session zone was never declared', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    sessionTimezoneWithSchema(fn () => sessionTimezoneWith((string) config('database.default'), 'SYSTEM', function (): void {
        [$output, $exitCode] = sessionTimezoneRun();

        // The case the whole policy turns on, against a real session rather than the pure matrix.
        // An implementation that hard-coded a `+00:00` comparison in the command and never called
        // the decision function would pass every other live test here and accept this one — the
        // deployment that declared nothing and happens to sit on a UTC host today.
        expect($output)->toContain('session time zone')
            ->and($output)->toContain('SYSTEM')
            ->and($exitCode)->toBe(1);
    }));
})->skip(fn (): bool => ! sessionTimezoneIsMysql(), 'Requires a MySQL/MariaDB session zone.');

/**
 * The other half of the coupling proof, and it is what makes the lane's coverage complete.
 *
 * `+00:00`, `+05:30` and `SYSTEM` do not on their own distinguish a command that calls
 * {@see SessionTimezoneAudit::rejects()} from one that hard-codes a `+00:00` comparison — all three
 * agree on those values. Only a named-UTC session separates them, so this is the case that proves
 * the command accepts what Verdict's own policy accepts rather than the offset spelling it was
 * probably written against.
 *
 * It was written expecting to skip on CI: named zones need the server's timezone tables, and the
 * `mysql:8` image is commonly shipped without them. That expectation was wrong — the lane resolves
 * `UTC` and this test runs there. The guard stays because the assumption it defends against is
 * real for other servers, and because a skip is the honest outcome when a server genuinely cannot
 * set a named zone; it is no longer a gap in what the lane proves.
 */
it('accepts the named UTC form against a real session', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $db = app(DatabaseManager::class)->connection();
    $original = (string) $db->selectOne('select @@session.time_zone as tz')->tz;

    try {
        $db->statement("SET time_zone = 'UTC'");
    } catch (QueryException $e) {
        // Only the server saying it does not know the zone. Anything else — a permission error, a
        // dropped connection, a syntax mistake in this test — is a real failure and must not be
        // reported as an expected container limitation.
        if (! str_contains($e->getMessage(), 'Unknown or incorrect time zone')) {
            throw $e;
        }

        $this->markTestSkipped('This server has no timezone tables loaded, so named zones cannot be set.');
    }

    try {
        sessionTimezoneWithSchema(function (): void {
            [$output, $exitCode] = sessionTimezoneRun();

            // The other half of the coupling proof: the command must accept what the decision
            // function accepts, not just the offset spelling it was probably written against.
            expect($output)->not->toContain('session time zone')
                ->and($exitCode)->toBe(0);
        });
    } finally {
        $db->statement('SET time_zone = ?', [$original]);
    }
})->skip(fn (): bool => ! sessionTimezoneIsMysql(), 'Requires a MySQL/MariaDB session zone.');
