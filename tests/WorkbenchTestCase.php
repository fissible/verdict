<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Illuminate\Foundation\Application;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class WorkbenchTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            WorkbenchServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../workbench/routes/web.php';
    }
}
