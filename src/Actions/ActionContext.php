<?php

declare(strict_types=1);

namespace Fissible\Verdict\Actions;

final readonly class ActionContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public mixed $actor,
        public array $metadata = [],
        public mixed $subject = null,
    ) {}
}
