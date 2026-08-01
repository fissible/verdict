<?php

declare(strict_types=1);

namespace Fissible\Verdict;

use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Policies\LaravelPolicyAuthorizer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class VerdictServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict.php', 'verdict');

        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(CapabilityAuthorizer::class, LaravelPolicyAuthorizer::class);

        $this->app->singleton(EvidenceRecorder::class, function (Container $app): EvidenceRecorder {
            $recorder = config('verdict.evidence.recorder', NullEvidenceRecorder::class);

            if (! is_string($recorder)) {
                throw new LogicException('The Verdict evidence recorder configuration must contain a class name.');
            }

            $instance = $app->make($recorder);

            if (! $instance instanceof EvidenceRecorder) {
                throw new LogicException("The [{$recorder}] evidence recorder must implement ".EvidenceRecorder::class.'.');
            }

            return $instance;
        });

        $this->app->singleton(VerdictManager::class, function (Container $app): VerdictManager {
            $message = config('verdict.ai.denied_message', 'This action was not authorized.');

            return new VerdictManager(
                capabilities: $app->make(CapabilityRegistry::class),
                authorizer: $app->make(CapabilityAuthorizer::class),
                evidence: $app->make(EvidenceRecorder::class),
                deniedMessage: is_string($message) ? $message : 'This action was not authorized.',
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verdict.php' => config_path('verdict.php'),
        ], ['verdict', 'verdict-config']);
    }
}
