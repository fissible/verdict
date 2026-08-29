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
 * #391: #356 and #363 gave the recorder column degradation keyed on *presence* — write whatever
 * the table happens to have. The changelog scopes that to columns from unapplied additive
 * migrations, but the mechanism cannot tell an additive column from one the create migration
 * itself produces. So an evidence table missing `record_type` takes the row, drops the column,
 * and every typed reader — each of which filters on `record_type` — never sees it again. No error
 * at write, no error at read, evidence permanently lost.
 *
 * The fix is to key degradation on *requiredness* instead, and the whole difficulty is choosing
 * that set correctly. Two wrong answers to rule out first.
 *
 * "Every column the create migration produces is required" is wrong, and would be a live
 * regression rather than a fix. The create stub has been amended in place five times — rate
 * limits, execution claims, target freshness, redaction, the config-driven table name — and
 * nothing back-fills those columns for an install that ran an earlier copy of it. Requiring them
 * would turn a working deployment's every guarded decision into a thrown exception, which is
 * exactly the #356 outage this whole line of work exists to prevent.
 *
 * "Only what a reader literally filters on today" is wrong in the other direction: it makes the
 * required set a function of whichever queries currently happen to exist, so the guarantee
 * changes silently whenever someone writes a new query.
 *
 * What these tests pin instead is the intersection that is stable in both directions: the columns
 * present in the *original* create migration (`c384f06`, and untouched by every amendment since)
 * that a reader depends on to find, type, order, or hydrate a row. Every one of them has existed
 * for the life of the table, so no published migration path produces a deployment that lacks one
 * — the throw has no reachable false positive — and none of them can stop being load-bearing
 * without a reader being rewritten.
 *
 * Two of them are scoped, and the scoping is the substance of "requiredness-keyed" rather than a
 * detail. `record_type`, `correlation_id` and `recorded_at` are required of the *table*: they
 * appear in provenanceFor()'s WHERE and ORDER BY, so their absence breaks that query for every
 * row in the table, whichever path wrote it. `source`, `trust` and `data_class` are required only
 * of the *provenance path*: they are hydrated into a ProvenanceEntry, but `record()` writes null
 * into all three, so a decision row loses nothing when they are gone. A guard that failed the
 * decision path over them would be presence-keyed again, one level up.
 *
 * `id` is not in these sets, and the omission is deliberate rather than an oversight. It is the
 * primary key; a table without it is not a table this fixture can build or a deployment anyone can
 * produce, so a claim about it would be untested prose. What is claimed here is claimed because it
 * is exercised.
 *
 * Two behaviours follow, and they are deliberately different:
 *
 *   - A *required* column is absent: throw. The table is not lagging, it is broken, and a broken
 *     evidence table must not look like a working one.
 *   - An *additive* column a reader needs is absent: write nothing, silently. That is a genuine
 *     migration lag on an install that predates the feature, and refusing the row is already what
 *     `recordProvenance()` does for `content_fingerprint`. It is the only outcome that keeps
 *     those installs running while still never leaving an unreadable row behind.
 *
 * Neither behaviour is "write it anyway". That is the whole point: after this, no configuration
 * of the table yields a row that was written and cannot be read.
 */

/**
 * Columns required on every write path, with the reason each one is load-bearing. If a candidate
 * implementation drops one of these from its required set, the row it writes becomes
 * unattributable — so each key here is an argument, not a label.
 */
dataset('required evidence columns', [
    'record_type — every typed read filters on it, so a row without it is invisible to all of them' => ['record_type'],
    'correlation_id — provenanceFor() looks a row up by it, and nothing else identifies the subject' => ['correlation_id'],
    'recorded_at — readers order by it, and the record_digest promise re-derives from it' => ['recorded_at'],
    'stage — not-null since the original create migration; a decision row without it is malformed' => ['stage'],
    'disposition — not-null since the original create migration; the outcome the record exists to carry' => ['disposition'],
]);

/**
 * Additionally required when writing provenance: provenanceFor() hydrates a ProvenanceEntry from
 * each of these, and all three are original create-migration columns like the set above.
 */
dataset('required provenance columns', [
    'source — hydrated into Source, and an unknown kind is a hard error at read' => ['source'],
    'trust — hydrated into the Trust enum, which has no empty case' => ['trust'],
    'data_class — hydrated into the DataClass enum, which has no empty case' => ['data_class'],
]);

/**
 * The provenance columns that arrived with `add_provenance_to_verdict_evidence_table`. An install
 * that never ran it legitimately lacks all four, so the answer here is to decline the write — not
 * to throw, which would break that install, and not to write, which is what leaves the
 * unreadable row. They are listed individually because a table can lack any one of them, and the
 * existing guard names only `content_fingerprint`.
 */
dataset('additive provenance columns', [
    'channel' => ['channel'],
    'component_label' => ['component_label'],
    'component_fingerprint' => ['component_fingerprint'],
    'content_fingerprint' => ['content_fingerprint'],
]);

/**
 * Columns nothing reads back. Their absence costs retained detail and `verdict:validate` reports
 * it, but it leaves the row findable and hydratable — so the recorder must keep writing. This is
 * the boundary that stops the fix from becoming "require everything".
 */
dataset('columns no reader depends on', [
    'reason' => ['reason'],
    'capability' => ['capability'],
    'payload_fingerprint' => ['payload_fingerprint'],
    'argument_fingerprint' => ['argument_fingerprint'],
    'trust_zone' => ['trust_zone'],
]);

function requiredColumnRecorder(): DatabaseEvidenceRecorder
{
    return new DatabaseEvidenceRecorder(app(DatabaseManager::class)->connection());
}

function requiredColumnRows(): Collection
{
    return app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->get();
}

function requiredColumnDecision(): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: 'envelope-391',
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'permit',
        reason: 'Within policy.',
        argumentFingerprint: str_repeat('a', 64),
        idempotencyKey: 'tool-call-391',
        approvalReceiptFingerprint: null,
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
        recordedAt: new DateTimeImmutable('2026-08-29 09:00:00', new DateTimeZone('UTC')),
        invocationId: 'invocation-391',
    );
}

function requiredColumnRelease(): ContextReleaseEvidence
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
        recordedAt: new DateTimeImmutable('2026-08-29 09:00:00', new DateTimeZone('UTC')),
        invocationId: 'invocation-391',
    );
}

function requiredColumnProvenance(): ProvenanceEntry
{
    return new ProvenanceEntry(
        correlationId: 'invocation-391',
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

/**
 * Runs a write that must fail loudly and returns what it threw.
 *
 * Asserting on the message rather than only on the class is deliberate: "it threw" is also
 * satisfied by the driver's own unknown-column error, which names neither the deployment problem
 * nor the remedy. The operator reading this in a log has to be told which column is missing.
 *
 * And naming the column is not by itself enough to tell the two apart — SQLite's own error reads
 * "table verdict_evidence has no column named record_type", which contains both the table and the
 * column. So {@see expectDeploymentDiagnostic()} also requires the message to point at the remedy.
 * A driver error never mentions migrations; only a deliberate check does.
 */
function requiredColumnFailure(callable $write): Throwable
{
    $thrown = null;

    try {
        $write();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull('the write must fail loudly rather than record an invisible row');
    assert($thrown instanceof Throwable);

    return $thrown;
}

/**
 * The message contract: which column, which table, and what to do about it. The third clause is
 * what distinguishes a deployment diagnostic from an incidental driver error that happens to
 * mention the same two names.
 */
function expectDeploymentDiagnostic(Throwable $thrown, string $column, string $table): void
{
    expect($thrown->getMessage())->toContain($column)
        ->and($thrown->getMessage())->toContain($table)
        ->and($thrown->getMessage())->toContain('migration');
}

afterEach(function (): void {
    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();
});

it('refuses to record a decision when a required column is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $thrown = requiredColumnFailure(fn () => requiredColumnRecorder()->record(requiredColumnDecision()));

    expectDeploymentDiagnostic($thrown, $column, verdictTable('evidence'));

    // Zero rows, not merely "it threw": a recorder that inserted the degraded row and then
    // complained would still have left the unreadable record #391 is about.
    expect(requiredColumnRows())->toHaveCount(0);
})->with('required evidence columns');

it('refuses to record a context release when a required column is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $thrown = requiredColumnFailure(fn () => requiredColumnRecorder()->recordRelease(requiredColumnRelease()));

    expectDeploymentDiagnostic($thrown, $column, verdictTable('evidence'));

    expect(requiredColumnRows())->toHaveCount(0);
})->with('required evidence columns');

it('refuses to record provenance when a required column is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();

    $thrown = requiredColumnFailure(fn () => $recorder->recordProvenance(requiredColumnProvenance()));

    expectDeploymentDiagnostic($thrown, $column, verdictTable('evidence'));

    expect(requiredColumnRows())->toHaveCount(0);
})->with('required evidence columns');

it('refuses to record provenance when a column its reader hydrates is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();

    // The sharpest form of #391. `content_fingerprint` is present, so the one guard that exists
    // today passes, the row is written, and provenanceFor() then fails or invents a value while
    // hydrating an enum from a column that is not there.
    $thrown = requiredColumnFailure(fn () => $recorder->recordProvenance(requiredColumnProvenance()));

    expectDeploymentDiagnostic($thrown, $column, verdictTable('evidence'));

    expect(requiredColumnRows())->toHaveCount(0);
})->with('required provenance columns');

it('declines a provenance write when an additive column its reader needs is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();

    // Not a throw. All four arrived in one migration, and an install that predates it is lagging
    // rather than broken — #356's whole subject. Declining the row keeps it running and still
    // leaves nothing unreadable behind, which throwing would not and writing would not.
    $recorder->recordProvenance(requiredColumnProvenance());

    expect(requiredColumnRows())->toHaveCount(0)
        ->and($recorder->provenanceFor('invocation-391'))->toBe([]);
})->with('additive provenance columns');

it('still records a decision when a column only the provenance reader hydrates is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();
    $recorder->record(requiredColumnDecision());

    // The scoping proof, and the test that stops the required set collapsing back into one flat
    // list. record() writes null into source, trust and data_class — a decision row has no
    // provenance to lose — so failing the decision path over them would refuse a write that was
    // never in danger, on a table where decisions read back perfectly.
    expect(requiredColumnRows())->toHaveCount(1);

    $row = (array) requiredColumnRows()->first();

    expect((string) $row['record_type'])->toBe('decision')
        ->and($row)->not->toHaveKey($column);
})->with('required provenance columns');

it('still records a context release when a column only the provenance reader hydrates is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();
    $recorder->recordRelease(requiredColumnRelease());

    // The uncomfortable half of the same rule, stated rather than hidden: a release row does put
    // real values in these columns, so this write loses evidence. It is still the right outcome
    // here, because nothing hydrates a release row back into an object — the loss is
    // *completeness*, which verdict:validate reports as an error before deployment, not
    // *readability*, which is what the recorder refuses over. Moving this column into the throwing
    // set is a defensible future change; doing it silently, by widening the set until it means
    // "every column", is what this test exists to prevent.
    expect(requiredColumnRows())->toHaveCount(1);

    $row = (array) requiredColumnRows()->first();

    expect((string) $row['record_type'])->toBe('context_release')
        ->and($row)->not->toHaveKey($column);
})->with('required provenance columns');

it('still records when a column no reader depends on is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();
    $recorder->record(requiredColumnDecision());

    $rows = requiredColumnRows();

    // The anti-overreach control, and the reason the required set is not simply "every column the
    // create migration makes". An implementation that required all of them would pass every test
    // above and fail here — and would have broken every install running a table built from an
    // earlier copy of the create stub.
    expect($rows)->toHaveCount(1);

    $row = (array) $rows->first();

    expect((string) $row['record_type'])->toBe('decision')
        ->and((string) $row['correlation_id'])->toBe('envelope-391')
        ->and((string) $row['recorded_at'])->toBe('2026-08-29 09:00:00')
        ->and($row)->not->toHaveKey($column);
})->with('columns no reader depends on');

it('leaves a provenance entry readable when a column no reader depends on is absent', function (string $column): void {
    EvidenceTableSchema::createWithoutColumns([$column]);

    $recorder = requiredColumnRecorder();
    $recorder->recordProvenance(requiredColumnProvenance());

    // The property #391's acceptance criteria state, on the surviving half: a row that was written
    // is a row its typed reader returns whole. Round-tripping the entry rather than counting rows
    // is what makes this the reader-side assertion the original tests never made.
    expect($recorder->provenanceFor('invocation-391'))->toEqual([requiredColumnProvenance()]);
})->with('columns no reader depends on');

it('records normally when the table is complete', function (): void {
    EvidenceTableSchema::createComplete();

    $recorder = requiredColumnRecorder();
    $recorder->record(requiredColumnDecision());
    $recorder->recordRelease(requiredColumnRelease());
    $recorder->recordProvenance(requiredColumnProvenance());

    // The positive control the throwing tests need. Without it, an implementation that refused
    // every write on every table would satisfy each "must not write an unreadable row" assertion
    // above, and the suite would be pinning an evidence recorder that records nothing.
    expect(requiredColumnRows())->toHaveCount(3)
        ->and($recorder->provenanceFor('invocation-391'))->toEqual([requiredColumnProvenance()]);
});

it('keeps checking the required columns without re-inspecting the schema per write', function (): void {
    EvidenceTableSchema::createComplete();

    $recorder = requiredColumnRecorder();
    $recorder->record(requiredColumnDecision());

    $introspections = 0;
    DB::listen(function ($query) use (&$introspections): void {
        if (str_contains($query->sql, 'pragma_table_') || str_contains($query->sql, 'sqlite_master')) {
            $introspections++;
        }
    });

    foreach (range(1, 5) as $ignored) {
        $recorder->record(requiredColumnDecision());
    }

    // #356 settled that the decision path does not query the schema per write, and a requiredness
    // check must not quietly reintroduce one. The memo is per instance; so is this.
    expect($introspections)->toBe(0)
        ->and(requiredColumnRows())->toHaveCount(6);
});
