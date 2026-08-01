<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Context\ContextTransformationResult;

interface ContextTransformer
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transform(array $payload): ContextTransformationResult;
}
