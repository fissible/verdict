<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Illuminate\Database\DatabaseManager;

/**
 * `docs/incident-response.md` publishes SQL an operator is told to paste into a client during an incident.
 * A wrong column, a wrong join, or a CTE that only works on one engine is invisible in prose review and
 * expensive at exactly the wrong moment, so the documented queries run here against the schema the
 * published migration stubs actually produce.
 *
 * Run it against a real engine, not only SQLite: the documented recursive CTE casts its anchor term
 * because PostgreSQL rejects an untyped parameter against the `char(64)` fingerprint columns, and SQLite
 * is too permissive to notice. Verified on PostgreSQL 16, MySQL 8, MariaDB 11, and SQLite; the
 * concurrency matrix is what exercises the first three.
 */
it('runs every query documented in docs/incident-response.md against the published schema', function (): void {
    $connection = app(DatabaseManager::class)->connection();

    $order = [
        'create_verdict_approval_receipts_table',
        'create_verdict_evidence_table',
        'create_verdict_rate_limit_buckets_table',
        'create_verdict_execution_claims_table',
        'add_provenance_to_verdict_evidence_table',
        'add_invocation_id_to_verdict_evidence_table',
        'create_verdict_provenance_derivations_table',
        'add_tool_kind_to_verdict_evidence_table',
        'add_configuration_fingerprint_to_verdict_evidence_table',
        'create_verdict_capability_configurations_table',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table',
        'add_target_source_to_verdict_evidence_table',
        'add_proposal_provenance_to_verdict_approval_receipts_table',
        'add_tool_description_fingerprints_to_verdict_evidence_table',
    ];

    $tables = [
        'verdict_evidence',
        'verdict_provenance_derivations',
        'verdict_approval_receipts',
        'verdict_capability_configurations',
        'verdict_rate_limit_buckets',
        'verdict_execution_claims',
    ];

    // Dropped before and after: these tables are created by other suites with their own hand-rolled
    // shapes, so leaving this one's behind makes an unrelated test fail depending on run order.
    foreach ($tables as $table) {
        $connection->getSchemaBuilder()->dropIfExists($table);
    }

    foreach ($order as $name) {
        (require __DIR__.'/../../database/migrations/'.$name.'.php.stub')->up();
    }

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-14 14:32:00', new DateTimeZone('UTC'));
        }
    };
    $recorder = new DatabaseEvidenceRecorder($connection);
    $ledger = new ProvenanceLedger($recorder, $recorder, $clock);

    $invocationId = 'inv-abc123';
    $arguments = ['order_id' => 7, 'reason' => 'duplicate charge'];
    $anchor = ArgumentFingerprint::make($arguments);

    $document = $ledger->record(
        correlationId: $invocationId,
        source: Source::external('support-kb'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::RetrievedDocument,
        content: 'Policy: cancel duplicate orders on sight.',
        componentLabel: 'kb-article-88',
    );
    $ledger->record(
        correlationId: $invocationId,
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: $arguments,
    );
    $ledger->declareDerivation(
        correlationId: $invocationId,
        childContentFingerprint: $anchor,
        parentContentFingerprints: [$document->contentFingerprint],
        kind: DerivationKind::Retrieved,
    );

    // Step 4, direct parents.
    $parents = $connection->select(
        'SELECT parent_content_fingerprint, kind FROM verdict_provenance_derivations
         WHERE correlation_id = ? AND child_content_fingerprint = ? ORDER BY recorded_at',
        [$invocationId, $anchor],
    );
    expect($parents)->toHaveCount(1)
        ->and($parents[0]->parent_content_fingerprint)->toBe($document->contentFingerprint)
        ->and($parents[0]->kind)->toBe('retrieved');

    // Step 4, the recursive CTE exactly as documented.
    $upstream = $connection->select(
        'WITH RECURSIVE upstream (content_fingerprint, depth) AS (
            SELECT CAST(? AS char(64)), 0
            UNION
            SELECT d.parent_content_fingerprint, u.depth + 1
              FROM verdict_provenance_derivations d
              JOIN upstream u ON d.child_content_fingerprint = u.content_fingerprint
             WHERE d.correlation_id = ?
        )
        SELECT content_fingerprint, depth FROM upstream WHERE depth > 0',
        [$anchor, $invocationId],
    );
    expect($upstream)->toHaveCount(1)
        ->and($upstream[0]->content_fingerprint)->toBe($document->contentFingerprint);

    // Step 4, resolving fingerprints back to what they were.
    $described = $connection->select(
        'SELECT content_fingerprint, channel, trust, data_class, source, component_label
         FROM verdict_evidence
         WHERE record_type = ? AND correlation_id = ? AND content_fingerprint = ?',
        ['provenance', $invocationId, $document->contentFingerprint],
    );
    expect($described)->toHaveCount(1)
        ->and($described[0]->channel)->toBe('retrieved_document')
        ->and($described[0]->trust)->toBe('untrusted')
        ->and($described[0]->source)->toBe('external:support-kb')
        ->and($described[0]->component_label)->toBe('kb-article-88');

    // Step 2's warning: provenance rows carry the invocation id in correlation_id.
    expect($connection->table('verdict_evidence')->where('record_type', 'provenance')->count())->toBe(2)
        ->and($connection->table('verdict_evidence')->where('record_type', 'provenance')->where('invocation_id', $invocationId)->count())->toBe(2);

    foreach ($tables as $table) {
        $connection->getSchemaBuilder()->dropIfExists($table);
    }
});
