<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;

final class VerdictRunIntegration
{
    /** @var array<string, class-string<TextProvider>> */
    public const array PROVIDERS = [
        'anthropic' => Providers\AnthropicProvider::class,
        'azure' => Providers\AzureOpenAiProvider::class,
        'bedrock' => Providers\BedrockProvider::class,
        'deepseek' => Providers\DeepSeekProvider::class,
        'gemini' => Providers\GeminiProvider::class,
        'groq' => Providers\GroqProvider::class,
        'mistral' => Providers\MistralProvider::class,
        'ollama' => Providers\OllamaProvider::class,
        'openai' => Providers\OpenAiProvider::class,
        'openai-compatible' => Providers\OpenAiCompatibleProvider::class,
        'openrouter' => Providers\OpenRouterProvider::class,
        'xai' => Providers\XaiProvider::class,
    ];

    public static function register(Container $app): void
    {
        $app->afterResolving(AiManager::class, self::install(...));

        if ($app->resolved(AiManager::class)) {
            self::install($app->make(AiManager::class));
        }
    }

    public static function install(AiManager $manager): void
    {
        // 1.0 has no public run-middleware registration on a resolved TextProvider. A generic
        // decorator would lose provider-specific interfaces/options and cannot override the
        // protected gatherMiddlewareFor(). Install native subclasses through AI's public driver
        // resolution seam instead: ordinary agent/provider configuration and SDK middleware survive.
        // `extend()` binds the callback to the manager (MultipleInstanceManager::bindCallbackToSelf),
        // so the closure must not be static and must not rely on `self::` (which would rebind to the
        // manager). Each provider is therefore constructed inline by its concrete class — which also
        // lets static analysis check the exact upstream constructor: three gateway-backed providers
        // take a gateway, the rest take config + events.
        foreach (array_keys(self::PROVIDERS) as $driver) {
            $manager->extend($driver, function (Container $app, array $config) use ($driver): TextProvider {
                $events = $app->make(Dispatcher::class);

                return match ($driver) {
                    'anthropic' => new Providers\AnthropicProvider(new AnthropicGateway($events), $config, $events),
                    'gemini' => new Providers\GeminiProvider(new GeminiGateway($events), $config, $events),
                    'openai' => new Providers\OpenAiProvider(new OpenAiGateway($events), $config, $events),
                    'azure' => new Providers\AzureOpenAiProvider($config, $events),
                    'bedrock' => new Providers\BedrockProvider($config, $events),
                    'deepseek' => new Providers\DeepSeekProvider($config, $events),
                    'groq' => new Providers\GroqProvider($config, $events),
                    'mistral' => new Providers\MistralProvider($config, $events),
                    'ollama' => new Providers\OllamaProvider($config, $events),
                    'openai-compatible' => new Providers\OpenAiCompatibleProvider($config, $events),
                    'openrouter' => new Providers\OpenRouterProvider($config, $events),
                    'xai' => new Providers\XaiProvider($config, $events),
                };
            });
        }
    }
}
