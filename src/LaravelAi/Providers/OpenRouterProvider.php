<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class OpenRouterProvider extends \Laravel\Ai\Providers\OpenRouterProvider
{
    use RunsVerdictMiddleware;
}
