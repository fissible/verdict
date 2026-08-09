<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Console\Commands\CompareEvaluationCommand;
use Fissible\Verdict\Console\Commands\CreateEvaluationBaselineCommand;
use Fissible\Verdict\Console\Commands\ListExecutionClaimsCommand;
use Fissible\Verdict\Console\Commands\PruneRateLimitBucketsCommand;
use Fissible\Verdict\Console\Commands\ResolveExecutionClaimCommand;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\FieldProjector;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Fissible\Verdict\LaravelAi\PromptProvenanceRegistry;
use Fissible\Verdict\LaravelAi\RecordAgentPromptProvenance;
use Fissible\Verdict\LaravelAi\RecordToolResultProvenance;
use Fissible\Verdict\Policies\LaravelPolicyAuthorizer;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitManager;
use Fissible\Verdict\Support\SystemClock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use LogicException;

final class VerdictServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict.php', 'verdict');

        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(ReleasePolicyRegistry::class);
        $this->app->singleton(FieldProjector::class);
        $this->app->singleton(CapabilityAuthorizer::class, LaravelPolicyAuthorizer::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->scoped(ApprovalExecutionContext::class);
        $this->app->scoped(InvocationContext::class);
        $this->app->scoped(PromptProvenanceRegistry::class);

        $this->app->singleton(ApprovalReceiptStore::class, function (Container $app): ApprovalReceiptStore {
            $store = config('verdict.approvals.store', DatabaseApprovalReceiptStore::class);

            if (! is_string($store)) {
                throw new LogicException('The Verdict approval receipt store configuration must contain a class name.');
            }

            if ($store === DatabaseApprovalReceiptStore::class) {
                $connection = config('verdict.approvals.connection');
                $table = config('verdict.approvals.table', 'verdict_approval_receipts');

                return new DatabaseApprovalReceiptStore(
                    connection: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                    table: is_string($table) ? $table : 'verdict_approval_receipts',
                );
            }

            $instance = $app->make($store);

            if (! $instance instanceof ApprovalReceiptStore) {
                throw new LogicException("The [{$store}] approval receipt store must implement ".ApprovalReceiptStore::class.'.');
            }

            return $instance;
        });

        $this->app->scoped(ApprovalManager::class, function (Container $app): ApprovalManager {
            $ttl = config('verdict.approvals.ttl_seconds', 900);

            return new ApprovalManager(
                receipts: $app->make(ApprovalReceiptStore::class),
                executionContext: $app->make(ApprovalExecutionContext::class),
                clock: $app->make(Clock::class),
                defaultTtlSeconds: is_int($ttl) ? $ttl : 900,
            );
        });

        $this->app->singleton(EvidenceRecorder::class, function (Container $app): EvidenceRecorder {
            $recorder = config('verdict.evidence.recorder', NullEvidenceRecorder::class);

            if (! is_string($recorder)) {
                throw new LogicException('The Verdict evidence recorder configuration must contain a class name.');
            }

            if ($recorder === DatabaseEvidenceRecorder::class) {
                $connection = config('verdict.evidence.connection');
                $table = config('verdict.evidence.table', 'verdict_evidence');

                return new DatabaseEvidenceRecorder(
                    connection: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                    table: is_string($table) ? $table : 'verdict_evidence',
                );
            }

            $instance = $app->make($recorder);

            if (! $instance instanceof EvidenceRecorder) {
                throw new LogicException("The [{$recorder}] evidence recorder must implement ".EvidenceRecorder::class.'.');
            }

            return $instance;
        });

        $this->app->singleton(RateLimitStore::class, function (Container $app): RateLimitStore {
            $store = config('verdict.rate_limits.store', DatabaseRateLimitStore::class);

            if (! is_string($store)) {
                throw new LogicException('The Verdict rate-limit store configuration must contain a class name.');
            }

            if ($store === DatabaseRateLimitStore::class) {
                $connection = config('verdict.rate_limits.connection');
                $table = config('verdict.rate_limits.table', 'verdict_rate_limit_buckets');

                return new DatabaseRateLimitStore(
                    connection: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                    table: is_string($table) ? $table : 'verdict_rate_limit_buckets',
                );
            }

            $instance = $app->make($store);

            if (! $instance instanceof RateLimitStore) {
                throw new LogicException("The [{$store}] rate-limit store must implement ".RateLimitStore::class.'.');
            }

            return $instance;
        });

        $this->app->scoped(RateLimitManager::class, fn (Container $app): RateLimitManager => new RateLimitManager(
            store: $app->make(RateLimitStore::class),
            clock: $app->make(Clock::class),
        ));

        $this->app->singleton(ExecutionClaimStore::class, function (Container $app): ExecutionClaimStore {
            $store = config('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);

            if (! is_string($store)) {
                throw new LogicException('The Verdict execution-claim store configuration must contain a class name.');
            }

            if ($store === DatabaseExecutionClaimStore::class) {
                $connection = config('verdict.execution_claims.connection');
                $table = config('verdict.execution_claims.table', 'verdict_execution_claims');

                return new DatabaseExecutionClaimStore(
                    connection: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                    table: is_string($table) ? $table : 'verdict_execution_claims',
                );
            }

            $instance = $app->make($store);

            if (! $instance instanceof ExecutionClaimStore) {
                throw new LogicException("The [{$store}] execution-claim store must implement ".ExecutionClaimStore::class.'.');
            }

            return $instance;
        });

        $this->app->scoped(ExecutionClaimManager::class, fn (Container $app): ExecutionClaimManager => new ExecutionClaimManager(
            store: $app->make(ExecutionClaimStore::class),
            clock: $app->make(Clock::class),
        ));

        $this->app->scoped(ContextReleaseManager::class, fn (Container $app): ContextReleaseManager => new ContextReleaseManager(
            policies: $app->make(ReleasePolicyRegistry::class),
            projector: $app->make(FieldProjector::class),
            evidence: $app->make(EvidenceRecorder::class),
            clock: $app->make(Clock::class),
        ));

        $this->app->scoped(ProvenanceLedger::class, fn (Container $app): ProvenanceLedger => new ProvenanceLedger(
            evidence: $app->make(EvidenceRecorder::class),
            clock: $app->make(Clock::class),
        ));

        $this->app->singleton(LiveEvaluationRunner::class, function (): LiveEvaluationRunner {
            $liveEnabled = config('verdict.evaluation.live_enabled', false);
            $maximumTrials = config('verdict.evaluation.maximum_trials', 25);

            return new LiveEvaluationRunner(
                liveEnabled: $liveEnabled === true,
                maximumTrials: is_int($maximumTrials) ? $maximumTrials : 25,
            );
        });

        $this->app->scoped(VerdictManager::class, function (Container $app): VerdictManager {
            $message = config('verdict.ai.denied_message', 'This action was not authorized.');

            return new VerdictManager(
                capabilities: $app->make(CapabilityRegistry::class),
                authorizer: $app->make(CapabilityAuthorizer::class),
                evidence: $app->make(EvidenceRecorder::class),
                approvals: $app->make(ApprovalManager::class),
                contextReleases: $app->make(ContextReleaseManager::class),
                rateLimits: $app->make(RateLimitManager::class),
                executionClaims: $app->make(ExecutionClaimManager::class),
                provenance: $app->make(ProvenanceLedger::class),
                deniedMessage: is_string($message) ? $message : 'This action was not authorized.',
            );
        });
    }

    public function boot(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(PromptingAgent::class, RecordAgentPromptProvenance::class);
        $events->listen(ToolInvoked::class, RecordToolResultProvenance::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CompareEvaluationCommand::class,
            CreateEvaluationBaselineCommand::class,
            ListExecutionClaimsCommand::class,
            PruneRateLimitBucketsCommand::class,
            ResolveExecutionClaimCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/verdict.php' => config_path('verdict.php'),
        ], ['verdict', 'verdict-config']);

        $approvalMigration = [
            __DIR__.'/../database/migrations/create_verdict_approval_receipts_table.php.stub' => database_path('migrations/2026_08_01_000000_create_verdict_approval_receipts_table.php'),
        ];
        $evidenceMigration = [
            __DIR__.'/../database/migrations/create_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_01_000001_create_verdict_evidence_table.php'),
            __DIR__.'/../database/migrations/add_provenance_to_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_01_000004_add_provenance_to_verdict_evidence_table.php'),
            __DIR__.'/../database/migrations/add_invocation_id_to_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_09_000005_add_invocation_id_to_verdict_evidence_table.php'),
        ];
        $rateLimitMigration = [
            __DIR__.'/../database/migrations/create_verdict_rate_limit_buckets_table.php.stub' => database_path('migrations/2026_08_01_000002_create_verdict_rate_limit_buckets_table.php'),
        ];
        $executionClaimMigration = [
            __DIR__.'/../database/migrations/create_verdict_execution_claims_table.php.stub' => database_path('migrations/2026_08_01_000003_create_verdict_execution_claims_table.php'),
        ];

        $this->publishesMigrations(
            [...$approvalMigration, ...$evidenceMigration, ...$rateLimitMigration, ...$executionClaimMigration],
            ['verdict', 'verdict-migrations'],
        );
        $this->publishesMigrations($approvalMigration, 'verdict-approval-migrations');
        $this->publishesMigrations($evidenceMigration, 'verdict-evidence-migrations');
        $this->publishesMigrations($rateLimitMigration, 'verdict-rate-limit-migrations');
        $this->publishesMigrations($executionClaimMigration, 'verdict-execution-claim-migrations');
    }
}
