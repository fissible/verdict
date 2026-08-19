<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\NullCapabilityConfigurationStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\Facades\Verdict;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

it('publishes the durable approval receipt migration', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-migrations',
    );

    expect($migrations)->toHaveCount(15)
        ->and(array_keys($migrations))->each->toEndWith('.php.stub')
        ->and(array_values($migrations))->each->toEndWith('.php');
});

it('publishes the durable evidence migration independently', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-evidence-migrations',
    );

    expect($migrations)->toHaveCount(10)
        ->and(array_keys($migrations)[0])->toEndWith('create_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[1])->toEndWith('add_provenance_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[2])->toEndWith('add_invocation_id_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[3])->toEndWith('create_verdict_provenance_derivations_table.php.stub')
        ->and(array_keys($migrations)[4])->toEndWith('add_tool_kind_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[5])->toEndWith('add_configuration_fingerprint_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[6])->toEndWith('add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[7])->toEndWith('add_target_source_to_verdict_evidence_table.php.stub')
        ->and(array_keys($migrations)[9])->toEndWith('add_record_identity_to_verdict_evidence_table.php.stub')
        ->and(array_values($migrations)[0])->toEndWith('create_verdict_evidence_table.php')
        ->and(array_values($migrations)[1])->toEndWith('add_provenance_to_verdict_evidence_table.php')
        ->and(array_values($migrations)[2])->toEndWith('add_invocation_id_to_verdict_evidence_table.php')
        ->and(array_values($migrations)[3])->toEndWith('create_verdict_provenance_derivations_table.php')
        ->and(array_values($migrations)[4])->toEndWith('add_tool_kind_to_verdict_evidence_table.php')
        ->and(array_values($migrations)[5])->toEndWith('add_configuration_fingerprint_to_verdict_evidence_table.php')
        ->and(array_values($migrations)[6])->toEndWith('add_actor_and_subject_fingerprints_to_verdict_evidence_table.php');
});

it('adds provenance columns without replacing existing evidence rows', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('verdict_evidence');
    $create = require __DIR__.'/../../database/migrations/create_verdict_evidence_table.php.stub';
    $addProvenance = require __DIR__.'/../../database/migrations/add_provenance_to_verdict_evidence_table.php.stub';
    $addInvocationId = require __DIR__.'/../../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub';
    $addToolKind = require __DIR__.'/../../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub';
    $addConfigurationFingerprint = require __DIR__.'/../../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub';
    $addActorAndSubjectFingerprints = require __DIR__.'/../../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub';

    $create->up();
    $connection->table('verdict_evidence')->insert([
        'id' => '019894b2-7af0-7000-8000-000000000001',
        'record_type' => 'decision',
        'correlation_id' => 'invocation-before-upgrade',
        'stage' => 'proposal',
        'disposition' => 'deny',
        'transformation_count' => 0,
        'recorded_at' => '2026-08-01 12:00:00',
    ]);

    $addProvenance->up();
    $addInvocationId->up();
    $addToolKind->up();
    $addConfigurationFingerprint->up();
    $addActorAndSubjectFingerprints->up();

    expect($schema->hasColumns('verdict_evidence', [
        'channel',
        'component_label',
        'component_fingerprint',
        'content_fingerprint',
        'invocation_id',
        'tool_kind',
        'configuration_fingerprint',
        'actor_fingerprint',
        'subject_fingerprint',
    ]))->toBeTrue()
        ->and($connection->table('verdict_evidence')->count())->toBe(1)
        ->and($connection->table('verdict_evidence')->value('correlation_id'))
        ->toBe('invocation-before-upgrade');

    $schema->dropIfExists('verdict_evidence');
});

it('resolves the configured database receipt store', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    $this->app->forgetInstance(ApprovalReceiptStore::class);

    expect(app(ApprovalReceiptStore::class))->toBeInstanceOf(DatabaseApprovalReceiptStore::class);
});

it('resolves the configured database evidence recorder', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    $this->app->forgetInstance(EvidenceRecorder::class);

    expect(app(EvidenceRecorder::class))->toBeInstanceOf(DatabaseEvidenceRecorder::class);
});

it('exposes the scoped provenance ledger through the manager and facade', function (): void {
    expect(app(ProvenanceLedger::class))->toBeInstanceOf(ProvenanceLedger::class)
        ->and(Verdict::provenance())->toBeInstanceOf(ProvenanceLedger::class);
});

it('publishes and resolves the database rate-limit store', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-rate-limit-migrations',
    );

    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    $this->app->forgetInstance(RateLimitStore::class);

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_rate_limit_buckets_table.php.stub')
        ->and(app(RateLimitStore::class))->toBeInstanceOf(DatabaseRateLimitStore::class);
});

it('publishes and resolves the database execution-claim store', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-execution-claim-migrations',
    );

    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    $this->app->forgetInstance(ExecutionClaimStore::class);

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_execution_claims_table.php.stub')
        ->and(app(ExecutionClaimStore::class))->toBeInstanceOf(DatabaseExecutionClaimStore::class);
});

it('publishes and resolves the durable capability configuration store', function (): void {
    $migrations = ServiceProvider::pathsToPublish(
        VerdictServiceProvider::class,
        'verdict-capability-configuration-migrations',
    );

    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);
    $this->app->forgetInstance(CapabilityConfigurationStore::class);

    expect($migrations)->toHaveCount(1)
        ->and(array_key_first($migrations))->toEndWith('create_verdict_capability_configurations_table.php.stub')
        ->and(app(CapabilityConfigurationStore::class))->toBeInstanceOf(DatabaseCapabilityConfigurationStore::class);
});

it('does not require the configuration-registry migration when evidence is not durable', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', null);
    $this->app->forgetInstance(CapabilityConfigurationStore::class);

    $store = app(CapabilityConfigurationStore::class);

    expect($store)->toBeInstanceOf(NullCapabilityConfigurationStore::class);

    (new CapabilityRegistry($store))->register(Capability::usingPolicy(
        name: 'orders.no-durable-evidence',
        ability: 'view',
        resolveTarget: fn (ActionEnvelope $envelope): null => null,
    ));
});

it('registers the expired rate-limit bucket pruning command', function (): void {
    $this->artisan('verdict:prune-rate-limits')
        ->expectsOutputToContain('Pruned 0 expired Verdict rate-limit bucket(s).')
        ->assertSuccessful();
});

it('ships database-backed approval receipt defaults', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = require __DIR__.'/../../config/verdict.php';

    expect($defaults['approvals']['store'])->toBe(DatabaseApprovalReceiptStore::class)
        ->and($defaults['capability_configurations']['store'])->toBeNull()
        ->and($defaults['capability_configurations']['table'])->toBe('verdict_capability_configurations')
        ->and($defaults['approvals']['table'])->toBe('verdict_approval_receipts')
        ->and($defaults['approvals']['ttl_seconds'])->toBe(900)
        ->and($defaults['evidence']['table'])->toBe('verdict_evidence')
        ->and($defaults['evidence']['connection'])->toBeNull()
        ->and($defaults['rate_limits']['store'])->toBe(DatabaseRateLimitStore::class)
        ->and($defaults['rate_limits']['table'])->toBe('verdict_rate_limit_buckets')
        ->and($defaults['rate_limits']['connection'])->toBeNull()
        ->and($defaults['execution_claims']['store'])->toBe(DatabaseExecutionClaimStore::class)
        ->and($defaults['execution_claims']['table'])->toBe('verdict_execution_claims')
        ->and($defaults['execution_claims']['connection'])->toBeNull();
});

it('ships disabled, bounded live evaluation defaults', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = require __DIR__.'/../../config/verdict.php';

    // Read from the shipped file rather than resolved config, so a setting the command falls back to
    // a default for — and therefore never fails on — cannot go missing from what an adopter publishes.
    expect($defaults['evaluation'])->toHaveKeys([
        'live_enabled',
        'maximum_trials',
        'minimum_security_pass_rate',
        'minimum_utility_pass_rate',
        'minimum_observations',
        'suites',
    ])
        ->and($defaults['evaluation']['live_enabled'])->toBeFalse()
        ->and($defaults['evaluation']['maximum_trials'])->toBe(25)
        ->and($defaults['evaluation']['minimum_observations'])->toBe(0);
});

it('resolves the configured live evaluation runner', function (): void {
    config()->set('verdict.evaluation.live_enabled', true);
    config()->set('verdict.evaluation.maximum_trials', 2);
    $this->app->forgetInstance(LiveEvaluationRunner::class);

    expect(app(LiveEvaluationRunner::class))->toBeInstanceOf(LiveEvaluationRunner::class);
});
