<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class GeminiProvider extends \Laravel\Ai\Providers\GeminiProvider
{
    use RunsVerdictMiddleware;
}
