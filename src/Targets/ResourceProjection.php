<?php

declare(strict_types=1);

namespace Fissible\Verdict\Targets;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use InvalidArgumentException;
use LogicException;

final readonly class ResourceProjection
{
    /** @var Closure(ActionEnvelope, mixed): array<string, mixed> */
    private Closure $projector;

    public string $contract;

    /** @param callable(ActionEnvelope, mixed): array<string, mixed> $using */
    private function __construct(string $contract, callable $using)
    {
        $contract = trim($contract);

        if ($contract === '') {
            throw new InvalidArgumentException('A resource projection must name a contract.');
        }

        $this->contract = $contract;
        $this->projector = Closure::fromCallable($using);
    }

    /** @param callable(ActionEnvelope, mixed): array<string, mixed> $using */
    public static function declared(string $contract, callable $using): self
    {
        return new self($contract, $using);
    }

    /** @return array<string, mixed> */
    public function project(ActionEnvelope $envelope, mixed $target): array
    {
        $projection = $this->resolveProjection($envelope, $target);

        if (! is_array($projection) || array_is_list($projection)) {
            throw new LogicException('A resource projection must be an associative array.');
        }

        $this->assertCanonical($projection);

        return $projection;
    }

    private function resolveProjection(ActionEnvelope $envelope, mixed $target): mixed
    {
        return ($this->projector)($envelope, $target);
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
            throw new LogicException('A resource projection may only contain arrays, scalar values, and null.');
        }
    }
}
