<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class BedrockProvider extends \Laravel\Ai\Providers\BedrockProvider
{
    use RunsVerdictMiddleware;
}
