<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Close every database connection the test opened before the application is torn down.
     *
     * Testbench builds a fresh application per test, but nothing closes the connections it opened
     * against a *server* database — SQLite has no client limit, so the default lane never notices.
     * On the real-database matrix those connections accumulate across the suite until the server
     * refuses new clients ("too many clients already"), which failed release CI unrelated to any
     * shipped defect. Purging here — the default connection and every named one — keeps the count
     * flat regardless of which test forgot to release its own. See #463.
     */
    protected function tearDown(): void
    {
        if ($this->app instanceof Application) {
            $database = $this->app->make(DatabaseManager::class);

            foreach (array_keys($database->getConnections()) as $name) {
                $database->purge($name);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            VerdictServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('verdict.evidence.recorder', InMemoryEvidenceRecorder::class);
        $app['config']->set('verdict.approvals.store', InMemoryApprovalReceiptStore::class);
        $app['config']->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);
        $app['config']->set('verdict.capability_configurations.store', InMemoryCapabilityConfigurationStore::class);
        $app['config']->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);
        $app['config']->set('verdict.execution_claims.store', InMemoryExecutionClaimStore::class);
        $app['config']->set('verdict.intents.store', InMemoryActionIntentStore::class);
    }
}
