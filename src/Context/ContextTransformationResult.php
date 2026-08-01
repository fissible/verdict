<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

final readonly class ContextTransformationResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $transformedPaths
     */
    public function __construct(
        public array $payload,
        public array $transformedPaths,
    ) {}
}
