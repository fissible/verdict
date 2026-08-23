<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Tests\Support\FrozenClock;
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

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Pinned before the workbench provider boots: registering capabilities resolves the rate
        // limit and approval managers, which capture the Clock at that moment. A binding made in a
        // test body would be too late. Mid-window, so a demo's attempts share one fixed window —
        // on the wall clock, a minute boundary between attempts let the third through (#288 CI).
        $app->instance(Clock::class, new FrozenClock);
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../workbench/routes/web.php';
    }
}
