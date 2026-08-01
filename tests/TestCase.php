<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
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
    }
}
