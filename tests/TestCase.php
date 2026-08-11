<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
        $app['config']->set('verdict.capability_configurations.store', InMemoryCapabilityConfigurationStore::class);
        $app['config']->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);
        $app['config']->set('verdict.execution_claims.store', InMemoryExecutionClaimStore::class);
    }
}
