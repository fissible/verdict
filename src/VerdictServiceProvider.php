<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\FieldProjector;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Policies\LaravelPolicyAuthorizer;
use Fissible\Verdict\Support\SystemClock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
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

            $instance = $app->make($recorder);

            if (! $instance instanceof EvidenceRecorder) {
                throw new LogicException("The [{$recorder}] evidence recorder must implement ".EvidenceRecorder::class.'.');
            }

            return $instance;
        });

        $this->app->scoped(ContextReleaseManager::class, fn (Container $app): ContextReleaseManager => new ContextReleaseManager(
            policies: $app->make(ReleasePolicyRegistry::class),
            projector: $app->make(FieldProjector::class),
            evidence: $app->make(EvidenceRecorder::class),
            clock: $app->make(Clock::class),
        ));

        $this->app->scoped(VerdictManager::class, function (Container $app): VerdictManager {
            $message = config('verdict.ai.denied_message', 'This action was not authorized.');

            return new VerdictManager(
                capabilities: $app->make(CapabilityRegistry::class),
                authorizer: $app->make(CapabilityAuthorizer::class),
                evidence: $app->make(EvidenceRecorder::class),
                approvals: $app->make(ApprovalManager::class),
                contextReleases: $app->make(ContextReleaseManager::class),
                deniedMessage: is_string($message) ? $message : 'This action was not authorized.',
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verdict.php' => config_path('verdict.php'),
        ], ['verdict', 'verdict-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_verdict_approval_receipts_table.php.stub' => database_path('migrations/2026_08_01_000000_create_verdict_approval_receipts_table.php'),
        ], ['verdict', 'verdict-migrations']);
    }
}
