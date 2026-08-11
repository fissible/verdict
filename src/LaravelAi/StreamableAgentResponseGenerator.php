<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use ReflectionProperty;

final class StreamableAgentResponseGenerator
{
    /**
     * Replace Laravel AI's protected generator in place, preserving the response object and all
     * framework-owned state registered on it. Fail explicitly if Laravel AI changes that internal
     * representation rather than silently skipping the deferred wrapper.
     */
    public static function wrap(StreamableAgentResponse $response, Closure $wrapper): void
    {
        $property = new ReflectionProperty(StreamableAgentResponse::class, 'generator');
        $originalGenerator = $property->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $wrappedGenerator = $wrapper($originalGenerator);

        if (! $wrappedGenerator instanceof Closure) {
            throw new LogicException('Expected the StreamableAgentResponse generator wrapper to return a Closure.');
        }

        $property->setValue($response, $wrappedGenerator);
    }
}
