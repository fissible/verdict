<?php

declare(strict_types=1);

namespace Fissible\Verdict\Actions;

use InvalidArgumentException;

final readonly class ActionProposal
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $capability,
        public array $arguments = [],
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A proposal must name a capability.');
        }
    }
}
