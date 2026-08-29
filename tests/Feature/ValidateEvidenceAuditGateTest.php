<?php

declare(strict_types=1);

use Fissible\AttestLaravel\Support\AttestRegistry;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;

/**
 * #395: `verdict:validate` decides whether to audit the evidence tables by reading the raw
 * `verdict.evidence.recorder` key, one line after computing the effective evidence class it
 * ignores. A deployment that routes writes through `verdict.evidence.writer` therefore writes to
 * the evidence table with no audit at all — and the audit is the only loud signal that table is
 * truncated, because a short row is written silently while the action proceeds.
 *
 * The gate is the subject here, not the audit itself: what `auditEvidenceRecorder()` reports once
 * it runs is already covered. These tests ask only "for which deployments does it run, and against
 * which tables".
 *
 * The issue proposes swapping the raw key for the effective class. That is not sufficient, and the
 * controls below say why. `writer` and `ledger` are independent narrow contracts, each falling back
 * to the legacy `recorder` when unset (config/verdict.php), so "the effective evidence class" is
 * two answers rather than one — and a deployment that overrides only the writer still serves every
 * provenance and derivation read out of those tables, through the recorder it left holding the
 * ledger role. Gating on the writer alone would fix the reported case and silently stop auditing
 * that one, trading a bug for its mirror image.
 *
 * What the gate actually has to ask is whether any *effective* evidence role touches the database
 * evidence tables — writer ?? recorder, and ledger ?? recorder, considered separately. Note the
 * consequence for the legacy key: naming a database-backed recorder is not on its own enough, since
 * a deployment that overrides both narrow roles has left that value behind and opens neither table.
 */
/**
 * A non-database stand-in for both narrow contracts. It implements ProvenanceLedgerStore as well as
 * EvidenceWriter because the tests below configure it as `ledger`, and the service provider rejects
 * a ledger that does not — a probe satisfying only half the pair would describe a deployment that
 * cannot boot, and a gate proved against an impossible configuration proves nothing.
 */
final class AuditGateProbeWriter implements EvidenceWriter, ProvenanceLedgerStore
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

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
 * Runs the audit against an evidence table that is not there, so "did the audit run" is legible as
 * "did it complain about the table". Returns the command's output and exit code together, from the
 * same invocation — a stateful implementation could otherwise print one deployment's audit and
 * exit on another's.
 *
 * @return array{0: string, 1: int}
 */
function auditGateRun(): array
{
    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();

    $exitCode = Artisan::call('verdict:validate');

    return [Artisan::output(), $exitCode];
}

/**
 * Whether the evidence audit ran, and against which table. The full diagnostic rather than a bare
 * table-name substring: several other stores emit a missing-table error of their own, and a test
 * that matched only the name would be satisfied by an unrelated failure — or by a hard-coded one.
 */
function expectAudited(string $output, string $table): void
{
    expect($output)->toContain("requires missing table [{$table}]");
}

function expectNotAudited(string $output): void
{
    expect($output)->not->toContain('Configured evidence recorder requires missing table');
}

/**
 * Builds schema on a named connection by making it the default for the duration. The fixture
 * resolves the default connection, and teaching it a connection argument would spread this one
 * test's need across every caller.
 */
function auditGateOnConnection(string $connection, callable $build): void
{
    $original = config('database.default');
    config()->set('database.default', $connection);

    try {
        $build();
    } finally {
        config()->set('database.default', $original);
    }
}

function auditGateProvenance(): ProvenanceEntry
{
    return new ProvenanceEntry(
        correlationId: 'invocation-395',
        source: Source::user('customer'),
        trust: Trust::Untrusted,
        dataClass: DataClass::PII,
        channel: ContextChannel::UserInput,
        contentFingerprint: ContentFingerprint::make('hello'),
        componentLabel: 'prompt',
        componentFingerprint: str_repeat('8', 64),
        recordedAt: new DateTimeImmutable('2026-08-29 09:00:00', new DateTimeZone('UTC')),
    );
}

function auditGateAttestChain(): void
{
    config()->set('verdict.evidence.attest.chain', 'verdict-395');
}

/**
 * Makes an attest deployment genuinely resolvable, which the tests asserting one is bootable need.
 * Attest's registry comes from the host application in production; the container cannot build it
 * unaided, so a test that resolves an Attest role has to supply it exactly as attest-laravel would.
 */
function auditGateAttestRegistry(): void
{
    app()->instance(AttestRegistry::class, AttestFixture::registry());
}

afterEach(function (): void {
    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();
});

it('audits the evidence tables when the legacy recorder key selects the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // The control that keeps the fix a widening rather than a swap: the configuration that is
    // audited today must still be audited afterwards.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('audits the evidence tables when a writer override selects the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // The reported defect. Evidence is going to the database, and nothing checks the table it is
    // going to.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('audits the evidence tables when a ledger override selects the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', DatabaseEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // The same defect through the other narrow contract. `ledger` is the read half: it serves
    // provenanceFor() and derivationsFor() out of these tables and writes nothing — ProvenanceLedger
    // writes through EvidenceWriter. A ledger pointed at a table the migrations never built does not
    // fail loudly at deploy time under the current gate; it just answers every provenance question
    // with an error or an empty list once traffic arrives.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('still audits when only the writer is overridden away from the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AuditGateProbeWriter::class);

    [$output, $exitCode] = auditGateRun();

    // The mirror image of the reported bug, and the test that rejects the one-line fix the issue
    // proposes. `ledger` is unset, so it falls back to the database recorder this deployment left
    // configured, and every provenance and derivation read is served from those tables. Gating on
    // the effective *writer* alone would stop auditing exactly here.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('does not audit the evidence tables when no evidence role uses them', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AuditGateProbeWriter::class);
    config()->set('verdict.evidence.ledger', AuditGateProbeWriter::class);

    [$output, $exitCode] = auditGateRun();

    // The other side of the gate, without which "audit everything" would pass every test above.
    // Nothing here touches those tables, so their absence is not this deployment's problem.
    expectNotAudited($output);
    expect($exitCode)->toBe(0);
});

it('does not audit the evidence tables for the shipped no-op default', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // A fresh install records nothing and must not be told to run evidence migrations it has no
    // use for. The Null recorder's own advisory is a warning and does not fail the command.
    expectNotAudited($output);
    expect($exitCode)->toBe(0);
});

/**
 * The issue's second acceptance criterion says an Attest deployment should not run this audit
 * "(it doesn't use that table)". The parenthetical is false, and these two tests are where that is
 * settled rather than assumed.
 *
 * The governing statement is the published contract in config/verdict.php, which tells operators
 * that under Attest "Provenance entries and derivations are always readable through this table".
 * That is a promise about the fallback table, and it is the reason the audit has to cover it. The
 * implementation agrees: `AttestEvidenceRecorder` delegates recordProvenance(), recordDerivation(),
 * provenanceFor() and derivationsFor() to a fallback the service provider constructs as a
 * DatabaseEvidenceRecorder, and writes its own `chain_gap` marker rows to that table directly — but
 * those are corroboration, not the specification. An Attest deployment with a truncated evidence
 * table is losing provenance silently with no deploy-time signal, on the recorder chosen
 * specifically for evidence integrity.
 *
 * What the criterion was right about is the table: an Attest deployment's fallback lives at
 * `verdict.evidence.attest.fallback_table`, so auditing `verdict.evidence.table` for it would
 * report on a table that deployment never writes.
 *
 * Attest is exercised only as the legacy `recorder`, and that is the whole supported surface
 * rather than a gap in these tests. The provider constructs AttestEvidenceRecorder in the recorder
 * branch alone, wiring its fallback, chain topology and connection from `attest.*`; the `writer`
 * and `ledger` bindings just call `make()` on whatever class they name, which cannot build a
 * recorder whose constructor takes a chain-id Closure with no default. config/verdict.php says the
 * same thing plainly — `attest.*` is "Only consulted when 'recorder' is AttestEvidenceRecorder".
 *
 * Unsupported by configuration rather than impossible, and the distinction is worth keeping: an
 * application is free to container-bind an AttestEvidenceRecorder it built itself, and this command
 * audits declared configuration rather than resolved bindings, so it would not see that either way.
 * What these tests decline to promise is coverage for a narrow-role Attest deployment the package
 * configures, because there is no such thing to configure.
 */
it('audits the fallback tables an attest deployment writes through', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    auditGateAttestChain();

    [$output, $exitCode] = auditGateRun();

    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('names the attest fallback table rather than the plain evidence table', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.fallback_table', 'custom_attest_fallback');
    auditGateAttestChain();

    EvidenceTableSchema::drop('custom_attest_fallback');

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Auditing the right tables, not merely auditing. An Attest deployment that renamed its
    // fallback would otherwise be told to migrate a table it does not use, while the one it does
    // use went unchecked — the failure #391 fixed for the derivations table, one key over.
    expectAudited($output, 'custom_attest_fallback');
    expect($exitCode)->toBe(1);
});

it('does not audit when the legacy recorder is a leftover no effective role uses', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AuditGateProbeWriter::class);
    config()->set('verdict.evidence.ledger', AuditGateProbeWriter::class);

    [$output, $exitCode] = auditGateRun();

    // The converse discriminator. Both narrow contracts are overridden, so nothing falls back to
    // the legacy key and no role touches those tables — the deployment has simply left a stale
    // value behind. An implementation that asked "does any of the three config values name the
    // database recorder" would pass every other test in this file and fail here, having told an
    // operator to migrate a table their deployment never opens.
    expectNotAudited($output);
    expect($exitCode)->toBe(0);
});

it('audits the attest fallback on the connection that deployment configured', function (): void {
    config()->set('database.connections.attest-fallback', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.fallback_connection', 'attest-fallback');
    auditGateAttestChain();

    // Both tables present on the default connection; on the configured fallback one, evidence
    // exists and derivations do not. So an audit that reads either table on the wrong connection
    // finds it and reports success, and this fails. Naming a table correctly is not the same as
    // looking for it in the right database, and the two tables have to be looked for in the same
    // one — a split that read evidence from the fallback and derivations from the default would
    // pass a test that only removed the evidence table.
    auditGateOnConnection('attest-fallback', function (): void {
        EvidenceTableSchema::createComplete();
        EvidenceTableSchema::dropDerivations();
    });

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expectAudited($output, verdictTable('derivations'));
    expect($exitCode)->toBe(1);
});

it('audits the derivations table an attest deployment writes through', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    auditGateAttestChain();

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::dropDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Derivations are the other half of what the fallback carries, and they have their own config
    // key rather than travelling with `fallback_table`. An audit that covered only the evidence
    // table would leave the table Attest writes every provenance edge to unchecked.
    expectAudited($output, verdictTable('derivations'));
    expect($exitCode)->toBe(1);
});

it('does not audit for an attest deployment whose narrow roles are both overridden', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AuditGateProbeWriter::class);
    config()->set('verdict.evidence.ledger', AuditGateProbeWriter::class);
    auditGateAttestChain();

    [$output, $exitCode] = auditGateRun();

    // The Attest converse. Without it, an implementation that special-cased the raw recorder key
    // being AttestEvidenceRecorder would pass every other Attest test here while auditing a stale
    // legacy value no effective role uses — the same mistake as the original bug, wearing the fix.
    expectNotAudited($output);
    expect($exitCode)->toBe(0);
});

it('names the configured derivations table for an attest deployment', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.derivations_table', 'custom_attest_derivations');
    auditGateAttestChain();

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::drop('custom_attest_derivations');

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Derivations do not travel with `fallback_table`: they keep their own key under Attest, so an
    // implementation that hard-coded the default name would pass the test above and fail here.
    expectAudited($output, 'custom_attest_derivations');
    expect($exitCode)->toBe(1);
});

it('audits the attest fallback evidence table on the configured connection', function (): void {
    config()->set('database.connections.attest-fallback', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.fallback_connection', 'attest-fallback');
    auditGateAttestChain();

    // The mirror of the test above, and it takes both to close the gap. That one leaves evidence
    // present and derivations absent on the fallback; this one inverts it. An implementation that
    // read one table from the fallback connection and the other from the default would satisfy
    // whichever single fixture happened to match its split, and fail the other.
    auditGateOnConnection('attest-fallback', function (): void {
        EvidenceTableSchema::drop();
        EvidenceTableSchema::createDerivations();
    });

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('audits an attest deployment whose ledger falls back to the legacy recorder', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AuditGateProbeWriter::class);
    auditGateAttestChain();
    auditGateAttestRegistry();

    // The premise, resolved rather than asserted in a comment: this configuration has to boot, and
    // the ledger has to actually be Attest, or the audit expectation below means nothing.
    expect(app(EvidenceWriter::class))->toBeInstanceOf(AuditGateProbeWriter::class)
        ->and(app(ProvenanceLedgerStore::class))->toBeInstanceOf(AttestEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // Attest reached asymmetrically, which is the only way a narrow role can reach it at all: the
    // recorder key still names Attest, so the provider builds it, and the unset ledger resolves to
    // it. That ledger is precisely what serves provenance and derivations out of the database
    // fallback, so this deployment reads those tables even though its writer does not touch them.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('audits an attest deployment whose writer falls back to the legacy recorder', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', AuditGateProbeWriter::class);
    auditGateAttestChain();
    auditGateAttestRegistry();

    expect(app(ProvenanceLedgerStore::class))->toBeInstanceOf(AuditGateProbeWriter::class)
        ->and(app(EvidenceWriter::class))->toBeInstanceOf(AttestEvidenceRecorder::class);

    [$output, $exitCode] = auditGateRun();

    // The other asymmetry. Here the writer resolves to Attest, whose chained writes land a
    // `chain_gap` marker row in the fallback table when they exhaust their retries — the record of
    // evidence that was lost. A deployment that cannot write those markers loses the gap too.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

/**
 * The audit reconstructs its recorder from `verdict.evidence.connection`, `table` and
 * `derivations_table`. A narrow-role override has to agree with that, or #395 trades a deployment
 * with no audit for one audited against tables it never opens — which is the #391 defect exactly,
 * one config key over, and not an improvement.
 *
 * The provider's `writer` and `ledger` bindings resolve the named class straight through the
 * container, so a DatabaseEvidenceRecorder named there is built on its constructor defaults and
 * silently ignores all three keys. On a deployment that renamed its evidence table, the writer
 * writes `verdict_evidence` while everything else in Verdict — the migrations, the audit, the
 * recorder branch — is pointed at the renamed one.
 */
it('resolves a database writer override against the configured tables', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'custom_writer_evidence');
    config()->set('verdict.evidence.derivations_table', 'custom_writer_derivations');

    $writer = App::make(EvidenceWriter::class);

    expect($writer)->toBeInstanceOf(DatabaseEvidenceRecorder::class);
    assert($writer instanceof DatabaseEvidenceRecorder);

    expect($writer->table())->toBe('custom_writer_evidence')
        ->and($writer->derivationsTable())->toBe('custom_writer_derivations');
});

it('audits the tables a database writer override actually writes', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'custom_writer_evidence');

    EvidenceTableSchema::drop('custom_writer_evidence');

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // The pair that matters: the test above pins what the writer opens, this one pins what the
    // audit looks at, and #395 is only fixed when they are the same table.
    expectAudited($output, 'custom_writer_evidence');
    expect($exitCode)->toBe(1);
});

it('resolves a database ledger override against the configured tables', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'custom_ledger_evidence');
    config()->set('verdict.evidence.derivations_table', 'custom_ledger_derivations');

    $ledger = App::make(ProvenanceLedgerStore::class);

    // The ledger binding takes the same container shortcut as the writer one, so it needs the same
    // proof: both roles read provenance and derivations back out of these tables, and a ledger
    // pointed at a different table than the migrations built reads an empty ledger forever.
    expect($ledger)->toBeInstanceOf(DatabaseEvidenceRecorder::class);
    assert($ledger instanceof DatabaseEvidenceRecorder);

    expect($ledger->table())->toBe('custom_ledger_evidence')
        ->and($ledger->derivationsTable())->toBe('custom_ledger_derivations');
});

it('resolves a database writer override on the configured connection', function (): void {
    config()->set('database.connections.writer-conn', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.connection', 'writer-conn');

    // The tables exist only on the configured connection, so a writer built on the container's
    // default connection cannot write at all. Table names alone would not catch that: the same
    // name on the wrong database is the failure this separates out.
    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();

    auditGateOnConnection('writer-conn', function (): void {
        EvidenceTableSchema::createComplete();
        EvidenceTableSchema::createDerivations();
    });

    $writer = App::make(EvidenceWriter::class);
    $writer->recordProvenance(auditGateProvenance());

    $rows = app(DatabaseManager::class)->connection('writer-conn')
        ->table(verdictTable('evidence'))->count();

    expect($rows)->toBe(1);
});

it('audits the configured derivations table for a database narrow-role override', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.derivations_table', 'custom_writer_derivations');

    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::drop('custom_writer_derivations');

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Evidence present, derivations absent: an audit that reached the narrow-role path but only
    // looked at the evidence table would report success here.
    expectAudited($output, 'custom_writer_derivations');
    expect($exitCode)->toBe(1);
});

it('resolves a database ledger override on the configured connection', function (): void {
    config()->set('database.connections.ledger-conn', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.connection', 'ledger-conn');

    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();

    auditGateOnConnection('ledger-conn', function (): void {
        EvidenceTableSchema::createComplete();
        EvidenceTableSchema::createDerivations();
    });

    $connection = app(DatabaseManager::class)->connection('ledger-conn');
    (new DatabaseEvidenceRecorder($connection))->recordProvenance(auditGateProvenance());

    // The ledger twin of the writer connection test, and it needs to exist separately because the
    // two bindings are separate: an implementation could honour the configured connection for the
    // writer and leave the ledger on the container default, and every other test here would still
    // pass while provenance was written to one database and read from another.
    expect(App::make(ProvenanceLedgerStore::class)->provenanceFor('invocation-395'))
        ->toEqual([auditGateProvenance()]);
});

it('audits the configured connection for a database ledger override', function (): void {
    config()->set('database.connections.ledger-audit-conn', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.connection', 'ledger-audit-conn');

    // Complete on the default connection, absent on the configured one. Resolving the ledger
    // correctly is not the same as auditing it correctly: a gate that noticed the ledger role but
    // reconstructed its recorder on the default connection would find healthy tables and pass.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('audits the configured connection for a database writer override', function (): void {
    config()->set('database.connections.writer-audit-conn', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.connection', 'writer-audit-conn');

    // The writer mirror of the ledger test above. Proving the writer *resolves* on the configured
    // connection says nothing about where the audit looks, and the two are separate code paths:
    // one is the provider's binding, the other is the command reconstructing a recorder. Both have
    // to land on the same database or the audit reports on one while traffic goes to the other.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

it('still audits when only the ledger is overridden away from the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.ledger', AuditGateProbeWriter::class);

    [$output, $exitCode] = auditGateRun();

    // The converse of the writer-overridden-away control, and the last cell of the role matrix.
    // Here `writer` is unset, so the legacy database recorder is what every decision, release,
    // provenance entry and derivation is written through — the half of the surface the other
    // control does not cover. An implementation that handled one fallback direction and not the
    // other would pass everything above.
    expectAudited($output, verdictTable('evidence'));
    expect($exitCode)->toBe(1);
});

/**
 * A test amendment agreed after the freeze, and recorded as such rather than folded in quietly.
 * Review of the implementation found a configuration the frozen spec never described: the database
 * and attest audits were written as one either/or decision, when they are two independent questions
 * about two different tables. Both models agreed the gap was real, so the tests were reopened by
 * consensus to describe it.
 */
it('audits both table sets when an attest deployment overrides one role to the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'mixed_writer_evidence');
    config()->set('verdict.evidence.attest.fallback_table', 'mixed_attest_fallback');
    auditGateAttestChain();

    EvidenceTableSchema::drop('mixed_writer_evidence');
    EvidenceTableSchema::drop('mixed_attest_fallback');

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Two roles, two tables, both opened. The effective writer is the database recorder and writes
    // `mixed_writer_evidence`; the ledger is unset and falls back to Attest, which serves
    // provenance and derivations from `mixed_attest_fallback`. An implementation that treats the
    // two audits as alternatives reports whichever it reaches first and leaves the other table —
    // in use, and missing — unmentioned.
    expectAudited($output, 'mixed_writer_evidence');
    expectAudited($output, 'mixed_attest_fallback');
    expect($exitCode)->toBe(1);
});
