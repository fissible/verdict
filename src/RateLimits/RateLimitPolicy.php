<?php

declare(strict_types=1);

namespace Fissible\Verdict\RateLimits;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use InvalidArgumentException;
use LogicException;

final readonly class RateLimitPolicy
{
    /** @var Closure(ActionEnvelope, mixed): mixed */
    private Closure $keyResolver;

    /**
     * @param  callable(ActionEnvelope, mixed): mixed  $keyUsing
     */
    private function __construct(
        public string $name,
        public int $limit,
        public int $windowSeconds,
        callable $keyUsing,
        public ?string $reason = null,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A rate-limit policy must have a name.');
        }

        if ($this->limit < 1) {
            throw new InvalidArgumentException('A rate-limit policy limit must be at least one.');
        }

        if ($this->windowSeconds < 1) {
            throw new InvalidArgumentException('A rate-limit policy window must be at least one second.');
        }

        $this->keyResolver = Closure::fromCallable($keyUsing);
    }

    /**
     * @param  callable(ActionEnvelope, mixed): mixed  $keyUsing
     */
    public static function fixedWindow(
        string $name,
        int $limit,
        int $windowSeconds,
        callable $keyUsing,
        ?string $reason = null,
    ): self {
        return new self($name, $limit, $windowSeconds, $keyUsing, $reason);
    }

    /** @return array<string, mixed> */
    public function binding(ActionEnvelope $envelope, mixed $target): array
    {
        $binding = ($this->keyResolver)($envelope, $target);

        if (! is_array($binding) || array_is_list($binding)) {
            throw new LogicException('A rate-limit binding must be an associative array.');
        }

        $this->assertCanonical($binding);

        return $binding;
    }

    private function assertCanonical(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertCanonical($item);
            }

            return;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw new LogicException('A rate-limit binding may only contain arrays, scalar values, and null.');
        }
    }
}
