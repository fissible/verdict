<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class GroqProvider extends \Laravel\Ai\Providers\GroqProvider
{
    use RunsVerdictMiddleware;
}
