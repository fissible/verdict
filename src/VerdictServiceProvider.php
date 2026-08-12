<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\AttestLaravel\Support\AttestRegistry;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\NullCapabilityConfigurationStore;
use Fissible\Verdict\Console\Commands\CompareEvaluationCommand;
use Fissible\Verdict\Console\Commands\CreateEvaluationBaselineCommand;
use Fissible\Verdict\Console\Commands\ListExecutionClaimsCommand;
use Fissible\Verdict\Console\Commands\MakeApprovalFlowCommand;
use Fissible\Verdict\Console\Commands\PruneRateLimitBucketsCommand;
use Fissible\Verdict\Console\Commands\ResolveExecutionClaimCommand;
use Fissible\Verdict\Console\Commands\ValidateVerdictCommand;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\FieldProjector;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\AttestChainResolver;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evaluation\LiveEvaluationRunner;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
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
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use LogicException;
use ReflectionClass;

final class VerdictServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict.php', 'verdict');

        $this->app->singleton(CapabilityConfigurationStore::class, function (Container $app): CapabilityConfigurationStore {
            $store = config('verdict.capability_configurations.store');

            if ($store === null) {
                $recorder = config('verdict.evidence.recorder', NullEvidenceRecorder::class);
                $store = in_array($recorder, [DatabaseEvidenceRecorder::class, AttestEvidenceRecorder::class], true)
                    ? DatabaseCapabilityConfigurationStore::class
                    : NullCapabilityConfigurationStore::class;
            }

            if (! is_string($store)) {
                throw new LogicException('The Verdict capability configuration store configuration must contain a class name.');
            }

            if ($store === DatabaseCapabilityConfigurationStore::class) {
                $connection = config('verdict.capability_configurations.connection');
                $table = config('verdict.capability_configurations.table', 'verdict_capability_configurations');

                return new DatabaseCapabilityConfigurationStore(
                    connection: $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                    table: is_string($table) ? $table : 'verdict_capability_configurations',
                );
            }

            $instance = $app->make($store);

            if (! $instance instanceof CapabilityConfigurationStore) {
                throw new LogicException("The [{$store}] capability configuration store must implement ".CapabilityConfigurationStore::class.'.');
            }

            return $instance;
        });
        $this->app->singleton(CapabilityRegistry::class, fn (Container $app): CapabilityRegistry => new CapabilityRegistry(
            $app->make(CapabilityConfigurationStore::class),
        ));
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

            if ($recorder === AttestEvidenceRecorder::class) {
                $fallbackConnection = config('verdict.evidence.attest.fallback_connection');
                $fallbackTable = config('verdict.evidence.attest.fallback_table', 'verdict_evidence');
                $chain = config('verdict.evidence.attest.chain');
                $resolverClass = config('verdict.evidence.attest.chain_resolver');
                $onFailure = config('verdict.evidence.attest.on_failure', 'alert');
                $chainProvenance = config('verdict.evidence.attest.chain_provenance', false);
                $maxAttempts = config('verdict.evidence.attest.max_attempts', 3);
                $baseDelayMs = config('verdict.evidence.attest.base_delay_ms', 50);

                if ($chain !== null && (! is_string($chain) || $chain === '')) {
                    throw new LogicException('The Verdict attest chain configuration must contain a chain id string.');
                }

                if ($resolverClass !== null && (! is_string($resolverClass) || $resolverClass === '')) {
                    throw new LogicException('The Verdict attest chain resolver configuration must contain a class name.');
                }

                if ($chain === null && $resolverClass === null) {
                    throw new LogicException(
                        'AttestEvidenceRecorder requires an explicit chain-topology decision: set '
                        .'verdict.evidence.attest.chain to a fixed chain id for a single shared chain, or '
                        .'verdict.evidence.attest.chain_resolver to a class implementing '
                        .AttestChainResolver::class.' for per-tenant chains. This choice is not safely '
                        ."changeable later — a chain's hash-linked history cannot be retroactively split "
                        .'by tenant. See docs/limitations.md.'
                    );
                }

                if ($chain !== null && $resolverClass !== null) {
                    throw new LogicException(
                        'AttestEvidenceRecorder received both verdict.evidence.attest.chain and '
                        .'verdict.evidence.attest.chain_resolver. Configure exactly one — they express '
                        .'mutually exclusive chain topologies.'
                    );
                }

                if ($resolverClass !== null) {
                    // Validate eagerly, and without constructing the resolver, so misconfiguration
                    // fails here rather than degrading silently on the first evidence write: a
                    // resolver that only fails inside $app->make() would be caught by
                    // AttestEvidenceRecorder::writeChained() and turned into a chain_gap marker,
                    // leaving the deployment permanently unchained without ever throwing.
                    if (! class_exists($resolverClass)) {
                        throw new LogicException("The [{$resolverClass}] chain resolver class does not exist or could not be autoloaded.");
                    }

                    if (! in_array(AttestChainResolver::class, class_implements($resolverClass) ?: [], true)) {
                        throw new LogicException("The [{$resolverClass}] chain resolver must implement ".AttestChainResolver::class.'.');
                    }

                    if (! (new ReflectionClass($resolverClass))->isInstantiable()) {
                        throw new LogicException("The [{$resolverClass}] chain resolver must be an instantiable class.");
                    }

                    // Resolve through $app on every write, deliberately never caching an instance,
                    // so a request-scoped or tenant-scoped binding inside resolve() is re-evaluated
                    // fresh each time. Caching a resolved instance here would reintroduce the exact
                    // stale-state-across-requests bug this design exists to avoid under Octane.
                    $chainIdUsing = static fn (): string => $app->make($resolverClass)->resolve();
                } elseif ($chain !== null) {
                    $chainIdUsing = static fn (): string => $chain;
                } else {
                    // Unreachable: the two throws above guarantee exactly one of $chain/
                    // $resolverClass is set, so this branch can never run. It exists only because
                    // $chainIdUsing must be definitely assigned on every path. Branching on
                    // `elseif ($chain !== null)` here — a direct, local, single-variable check —
                    // rather than a bare `else` relying on the two throws above being deduced
                    // through an arrow function's implicit capture keeps the narrowing of $chain
                    // to non-empty-string version-independent: that cross-variable deduction is
                    // not reliably supported across the PHPStan/Larastan versions this package's
                    // dependency range spans (confirmed absent on the lowest supported version).
                    throw new LogicException('Unreachable: attest chain topology validated above.');
                }

                $connection = $app->make(DatabaseManager::class)->connection(
                    is_string($fallbackConnection) ? $fallbackConnection : null,
                );

                return new AttestEvidenceRecorder(
                    attest: $app->make(AttestRegistry::class),
                    fallback: new DatabaseEvidenceRecorder(
                        connection: $connection,
                        table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    ),
                    connection: $connection,
                    events: $app->make(Dispatcher::class),
                    chainIdUsing: $chainIdUsing,
                    table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    chainProvenance: (bool) $chainProvenance,
                    onFailure: is_string($onFailure) ? $onFailure : 'alert',
                    maxAttempts: is_int($maxAttempts) ? $maxAttempts : 3,
                    baseDelayMs: is_int($baseDelayMs) ? $baseDelayMs : 50,
                );
            }

            $instance = $app->make($recorder);

            if (! $instance instanceof EvidenceRecorder) {
                throw new LogicException("The [{$recorder}] evidence recorder must implement ".EvidenceRecorder::class.'.');
            }

            return $instance;
        });

        // `recorder` remains the pre-1.0 compatibility configuration. New adapters can provide
        // only the responsibility they own by configuring `writer` and/or `ledger` instead.
        $this->app->singleton(EvidenceWriter::class, function (Container $app): EvidenceWriter {
            $writer = config('verdict.evidence.writer');

            if ($writer === null) {
                return $app->make(EvidenceRecorder::class);
            }

            if (! is_string($writer)) {
                throw new LogicException('The Verdict evidence writer configuration must contain a class name.');
            }

            $instance = $app->make($writer);

            if (! $instance instanceof EvidenceWriter) {
                throw new LogicException("The [{$writer}] evidence writer must implement ".EvidenceWriter::class.'.');
            }

            return $instance;
        });

        $this->app->singleton(ProvenanceLedgerStore::class, function (Container $app): ProvenanceLedgerStore {
            $ledger = config('verdict.evidence.ledger');

            if ($ledger === null) {
                return $app->make(EvidenceRecorder::class);
            }

            if (! is_string($ledger)) {
                throw new LogicException('The Verdict provenance ledger configuration must contain a class name.');
            }

            $instance = $app->make($ledger);

            if (! $instance instanceof ProvenanceLedgerStore) {
                throw new LogicException("The [{$ledger}] provenance ledger must implement ".ProvenanceLedgerStore::class.'.');
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
            evidence: $app->make(EvidenceWriter::class),
            clock: $app->make(Clock::class),
            invocations: $app->make(InvocationContext::class),
            provenance: $app->make(ProvenanceLedger::class),
        ));

        $this->app->scoped(ProvenanceLedger::class, fn (Container $app): ProvenanceLedger => new ProvenanceLedger(
            writer: $app->make(EvidenceWriter::class),
            store: $app->make(ProvenanceLedgerStore::class),
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
                evidence: $app->make(EvidenceWriter::class),
                approvals: $app->make(ApprovalManager::class),
                contextReleases: $app->make(ContextReleaseManager::class),
                rateLimits: $app->make(RateLimitManager::class),
                executionClaims: $app->make(ExecutionClaimManager::class),
                provenance: $app->make(ProvenanceLedger::class),
                invocations: $app->make(InvocationContext::class),
                deniedMessage: is_string($message) ? $message : 'This action was not authorized.',
            );
        });
    }

    public function boot(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(PromptingAgent::class, RecordAgentPromptProvenance::class);
        $events->listen(StreamingAgent::class, RecordAgentPromptProvenance::class);
        $events->listen(ToolInvoked::class, RecordToolResultProvenance::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CompareEvaluationCommand::class,
            CreateEvaluationBaselineCommand::class,
            ListExecutionClaimsCommand::class,
            MakeApprovalFlowCommand::class,
            PruneRateLimitBucketsCommand::class,
            ResolveExecutionClaimCommand::class,
            ValidateVerdictCommand::class,
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
            __DIR__.'/../database/migrations/create_verdict_provenance_derivations_table.php.stub' => database_path('migrations/2026_08_09_000006_create_verdict_provenance_derivations_table.php'),
            __DIR__.'/../database/migrations/add_tool_kind_to_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_10_000007_add_tool_kind_to_verdict_evidence_table.php'),
            __DIR__.'/../database/migrations/add_configuration_fingerprint_to_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_10_000008_add_configuration_fingerprint_to_verdict_evidence_table.php'),
            __DIR__.'/../database/migrations/add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub' => database_path('migrations/2026_08_11_000010_add_actor_and_subject_fingerprints_to_verdict_evidence_table.php'),
        ];
        $rateLimitMigration = [
            __DIR__.'/../database/migrations/create_verdict_rate_limit_buckets_table.php.stub' => database_path('migrations/2026_08_01_000002_create_verdict_rate_limit_buckets_table.php'),
        ];
        $executionClaimMigration = [
            __DIR__.'/../database/migrations/create_verdict_execution_claims_table.php.stub' => database_path('migrations/2026_08_01_000003_create_verdict_execution_claims_table.php'),
        ];
        $capabilityConfigurationMigration = [
            __DIR__.'/../database/migrations/create_verdict_capability_configurations_table.php.stub' => database_path('migrations/2026_08_10_000009_create_verdict_capability_configurations_table.php'),
        ];

        $this->publishesMigrations(
            [...$approvalMigration, ...$evidenceMigration, ...$rateLimitMigration, ...$executionClaimMigration, ...$capabilityConfigurationMigration],
            ['verdict', 'verdict-migrations'],
        );
        $this->publishesMigrations($approvalMigration, 'verdict-approval-migrations');
        $this->publishesMigrations($evidenceMigration, 'verdict-evidence-migrations');
        $this->publishesMigrations($rateLimitMigration, 'verdict-rate-limit-migrations');
        $this->publishesMigrations($executionClaimMigration, 'verdict-execution-claim-migrations');
        $this->publishesMigrations($capabilityConfigurationMigration, 'verdict-capability-configuration-migrations');
    }
}
