<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class OpenAiCompatibleProvider extends \Laravel\Ai\Providers\OpenAiCompatibleProvider
{
    use RunsVerdictMiddleware;
}
