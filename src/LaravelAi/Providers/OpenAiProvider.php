<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class OpenAiProvider extends \Laravel\Ai\Providers\OpenAiProvider
{
    use RunsVerdictMiddleware;
}
