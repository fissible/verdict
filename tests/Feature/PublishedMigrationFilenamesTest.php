<?php

declare(strict_types=1);

use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * #483 — what a published migration filename is, and what may be done to one.
 *
 * A published filename is not a label. Laravel keys the `migrations` table by it, and
 * `VendorPublishCommand::publishFile()` decides whether to copy by whether that exact destination
 * path already exists, with `database.migrations.update_date_on_publish` off by default. So renaming
 * a stub's destination after release writes a SECOND file into every existing install, unrun, which
 * `migrate` then runs against a table that already exists.
 *
 * That is why the timestamp collision recorded below is grandfathered rather than tidied, and it is
 * the same invariant #466 broke one layer down: a create migration's column list and a published
 * migration's filename are both historical artifacts of what is already in the field.
 */

/** @return array<string, string> stub basename => published migration basename, no extensions */
function publishedMigrationMap(): array
{
    $map = [];

    foreach (ServiceProvider::pathsToPublish(VerdictServiceProvider::class, 'verdict-migrations') as $stub => $destination) {
        $map[basename($stub, '.php.stub')] = basename($destination, '.php');
    }

    return $map;
}

/**
 * Every stub published by v0.15.0, with the destination it published to. Frozen: an entry here
 * describes a file that exists in deployments, so it may be ADDED to when a new migration ships and
 * never edited. Changing a value is the rename this file exists to prevent.
 */
const V0_15_0_PUBLISHED_MIGRATIONS = [
    'create_verdict_approval_receipts_table' => '2026_08_01_000000_create_verdict_approval_receipts_table',
    'create_verdict_evidence_table' => '2026_08_01_000001_create_verdict_evidence_table',
    'create_verdict_rate_limit_buckets_table' => '2026_08_01_000002_create_verdict_rate_limit_buckets_table',
    'create_verdict_execution_claims_table' => '2026_08_01_000003_create_verdict_execution_claims_table',
    'add_provenance_to_verdict_evidence_table' => '2026_08_01_000004_add_provenance_to_verdict_evidence_table',
    'add_invocation_id_to_verdict_evidence_table' => '2026_08_09_000005_add_invocation_id_to_verdict_evidence_table',
    'create_verdict_provenance_derivations_table' => '2026_08_09_000006_create_verdict_provenance_derivations_table',
    'add_tool_kind_to_verdict_evidence_table' => '2026_08_10_000007_add_tool_kind_to_verdict_evidence_table',
    'add_configuration_fingerprint_to_verdict_evidence_table' => '2026_08_10_000008_add_configuration_fingerprint_to_verdict_evidence_table',
    'create_verdict_capability_configurations_table' => '2026_08_10_000009_create_verdict_capability_configurations_table',
    'add_actor_and_subject_fingerprints_to_verdict_evidence_table' => '2026_08_11_000010_add_actor_and_subject_fingerprints_to_verdict_evidence_table',
    'add_target_source_to_verdict_evidence_table' => '2026_08_16_000011_add_target_source_to_verdict_evidence_table',
    'add_proposal_provenance_to_verdict_approval_receipts_table' => '2026_08_16_000012_add_proposal_provenance_to_verdict_approval_receipts_table',
    'add_tool_description_fingerprints_to_verdict_evidence_table' => '2026_08_17_000013_add_tool_description_fingerprints_to_verdict_evidence_table',
    'add_record_identity_to_verdict_evidence_table' => '2026_08_19_000014_add_record_identity_to_verdict_evidence_table',
    'add_approval_context_to_verdict_approval_receipts_table' => '2026_08_24_000013_add_approval_context_to_verdict_approval_receipts_table',
    'add_intent_id_to_verdict_evidence_table' => '2026_08_25_000015_add_intent_id_to_verdict_evidence_table',
    'create_verdict_action_intents_table' => '2026_08_25_000016_create_verdict_action_intents_table',
    'create_verdict_review_requests_table' => '2026_08_30_000017_create_verdict_review_requests_table',
    'add_review_outcome_to_verdict_evidence_table' => '2026_08_31_000018_add_review_outcome_to_verdict_evidence_table',
    'add_approver_summary_to_verdict_approval_receipts_table' => '2026_08_31_000019_add_approver_summary_to_verdict_approval_receipts_table',
    'add_pending_enumeration_index_to_verdict_approval_receipts_table' => '2026_08_31_000020_add_pending_enumeration_index_to_verdict_approval_receipts_table',
    'create_verdict_approval_operations_table' => '2026_08_31_000020_create_verdict_approval_operations_table',
];

/**
 * The one shipped timestamp collision, grandfathered by name.
 *
 * Both went out in v0.15.0 at `2026_08_31_000020`. They still order deterministically — Laravel sorts
 * on the whole filename, and `add_…` precedes `create_…` — and they target unrelated tables, so the
 * order between them cannot matter. It stays because it cannot be renamed, not because it is right.
 *
 * Do not add to this list. The next pair might share a table, and for a shared table the same lexical
 * fallback puts the `add_…` ALTER ahead of the `create_…` it depends on.
 */
const GRANDFATHERED_TIMESTAMP_COLLISION = [
    'add_pending_enumeration_index_to_verdict_approval_receipts_table',
    'create_verdict_approval_operations_table',
];

it('never changes where a released stub publishes to', function (): void {
    $published = publishedMigrationMap();

    foreach (V0_15_0_PUBLISHED_MIGRATIONS as $stub => $destination) {
        // Asserted per stub rather than as one array comparison: the failure message then names the
        // migration whose destination moved, which is the only thing a reader needs to know.
        expect($published)->toHaveKey($stub)
            ->and($published[$stub])->toBe($destination, "published destination for [{$stub}]");
    }
});

it('publishes every migration to a distinct timestamp, but for the one collision already in the field', function (): void {
    $byTimestamp = [];

    foreach (publishedMigrationMap() as $stub => $destination) {
        // 'Y_m_d_His' — four fields, then the descriptive remainder.
        $timestamp = implode('_', array_slice(explode('_', $destination), 0, 4));
        $byTimestamp[$timestamp][] = $stub;
    }

    $collisions = [];

    foreach ($byTimestamp as $timestamp => $stubs) {
        if (count($stubs) > 1) {
            sort($stubs);
            $collisions[$timestamp] = $stubs;
        }
    }

    $grandfathered = GRANDFATHERED_TIMESTAMP_COLLISION;
    sort($grandfathered);

    expect($collisions)->toBe(['2026_08_31_000020' => $grandfathered]);
});

it('publishes each table\'s create migration before every migration that alters it', function (): void {
    $published = publishedMigrationMap();

    foreach ($published as $stub => $destination) {
        if (! str_starts_with($stub, 'add_')) {
            continue;
        }

        // The stub names the table it alters: add_<what>_to_<table>_table.
        $position = strrpos($stub, '_to_');
        expect($position)->not->toBeFalse("[{$stub}] does not name the table it alters");

        $create = 'create_'.substr($stub, $position + 4);

        if (! array_key_exists($create, $published)) {
            continue; // alters a table another package or the application owns
        }

        // The property the grandfathered collision is one letter away from breaking: on a shared
        // timestamp the lexical tiebreak runs `add_…` first, so an ALTER would precede its CREATE.
        expect($destination)->toBeGreaterThan(
            $published[$create],
            "[{$stub}] publishes before [{$create}], so it would ALTER a table that does not exist yet",
        );
    }
});
