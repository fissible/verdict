<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi\Providers;

use Fissible\Verdict\LaravelAi\RunsVerdictMiddleware;

class MistralProvider extends \Laravel\Ai\Providers\MistralProvider
{
    use RunsVerdictMiddleware;
}
