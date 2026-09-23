<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class AzureOpenAiProvider extends \Laravel\Ai\Providers\AzureOpenAiProvider
{
    use RunsVerdictMiddleware;
}
