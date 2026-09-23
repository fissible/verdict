<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class XaiProvider extends \Laravel\Ai\Providers\XaiProvider
{
    use RunsVerdictMiddleware;
}
