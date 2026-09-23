<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class OllamaProvider extends \Laravel\Ai\Providers\OllamaProvider
{
    use RunsVerdictMiddleware;
}
