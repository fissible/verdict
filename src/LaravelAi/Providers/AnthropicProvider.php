<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class AnthropicProvider extends \Laravel\Ai\Providers\AnthropicProvider
{
    use RunsVerdictMiddleware;
}
