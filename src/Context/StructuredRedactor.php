<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use Fissible\Verdict\Contracts\ContextTransformer;
use InvalidArgumentException;

final readonly class StructuredRedactor implements ContextTransformer
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        private array $paths,
        private string $replacement = '[REDACTED]',
    ) {
        if ($this->paths === [] || trim($this->replacement) === '') {
            throw new InvalidArgumentException('A structured redactor requires field paths and a non-empty replacement.');
        }

        foreach ($this->paths as $path) {
            $segments = explode('.', $path);

            if (trim($path) === '' || in_array('', $segments, true)) {
                throw new InvalidArgumentException("The redaction field path [{$path}] is invalid.");
            }
        }
    }

    public function name(): string
    {
        return 'structured_redaction';
    }

    public function transform(array $payload): ContextTransformationResult
    {
        $transformedPaths = [];

        foreach ($this->paths as $path) {
            $payload = $this->redact(
                value: $payload,
                segments: explode('.', $path),
                prefix: [],
                transformedPaths: $transformedPaths,
            );
        }

        return new ContextTransformationResult(
            payload: $payload,
            transformedPaths: array_values(array_unique($transformedPaths)),
        );
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $prefix
     * @param  list<string>  $transformedPaths
     */
    private function redact(mixed $value, array $segments, array $prefix, array &$transformedPaths): mixed
    {
        if (! is_array($value) || $segments === []) {
            return $value;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            foreach ($value as $key => $item) {
                $value[$key] = $segments === []
                    ? $this->replace($prefix, (string) $key, $transformedPaths)
                    : $this->redact($item, $segments, [...$prefix, (string) $key], $transformedPaths);
            }

            return $value;
        }

        if (! array_key_exists($segment, $value)) {
            return $value;
        }

        if ($segments === []) {
            $value[$segment] = $this->replace($prefix, $segment, $transformedPaths);

            return $value;
        }

        $value[$segment] = $this->redact(
            $value[$segment],
            $segments,
            [...$prefix, $segment],
            $transformedPaths,
        );

        return $value;
    }

    /**
     * @param  list<string>  $prefix
     * @param  list<string>  $transformedPaths
     */
    private function replace(array $prefix, string $segment, array &$transformedPaths): string
    {
        $transformedPaths[] = implode('.', [...$prefix, $segment]);

        return $this->replacement;
    }
}
