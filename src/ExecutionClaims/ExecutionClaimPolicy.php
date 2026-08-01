<?php

declare(strict_types=1);

namespace Fissible\Verdict\ExecutionClaims;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use InvalidArgumentException;
use LogicException;

final readonly class ExecutionClaimPolicy
{
    /** @var Closure(ActionEnvelope, mixed): mixed */
    private Closure $keyResolver;

    /** @param callable(ActionEnvelope, mixed): mixed $keyUsing */
    private function __construct(
        public string $name,
        callable $keyUsing,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('An execution-claim policy must have a name.');
        }

        $this->keyResolver = Closure::fromCallable($keyUsing);
    }

    /** @param callable(ActionEnvelope, mixed): mixed $keyUsing */
    public static function named(string $name, callable $keyUsing): self
    {
        return new self($name, $keyUsing);
    }

    /** @return array<string, mixed> */
    public function binding(ActionEnvelope $envelope, mixed $target): array
    {
        $binding = ($this->keyResolver)($envelope, $target);

        if (! is_array($binding) || array_is_list($binding)) {
            throw new LogicException('An execution-claim binding must be an associative array.');
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
            throw new LogicException('An execution-claim binding may only contain arrays, scalar values, and null.');
        }
    }
}
