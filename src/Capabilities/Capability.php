<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use InvalidArgumentException;

final readonly class Capability
{
    /**
     * @var Closure(ActionEnvelope): mixed
     */
    private Closure $targetResolver;

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    private function __construct(
        public string $name,
        public string $ability,
        callable $resolveTarget,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A capability must have a name.');
        }

        if (trim($this->ability) === '') {
            throw new InvalidArgumentException('A capability must name a Laravel authorization ability.');
        }

        $this->targetResolver = Closure::fromCallable($resolveTarget);
    }

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    public static function usingPolicy(string $name, string $ability, callable $resolveTarget): self
    {
        return new self($name, $ability, $resolveTarget);
    }

    public function resolveTarget(ActionEnvelope $envelope): mixed
    {
        return ($this->targetResolver)($envelope);
    }
}
