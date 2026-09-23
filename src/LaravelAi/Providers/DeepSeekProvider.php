<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class DeepSeekProvider extends \Laravel\Ai\Providers\DeepSeekProvider
{
    use RunsVerdictMiddleware;
}
