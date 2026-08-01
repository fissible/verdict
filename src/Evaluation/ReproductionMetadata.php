<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class ReproductionMetadata
{
    /**
     * @param  array<string, string>  $components
     */
    public function __construct(
        public array $components = [],
    ) {
        foreach ($this->components as $name => $version) {
            if (trim($name) === '' || trim($version) === '') {
                throw new InvalidArgumentException('Reproduction component names and versions must be non-empty.');
            }
        }
    }
}
