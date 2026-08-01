<?php

declare(strict_types=1);

namespace Fissible\Verdict\Targets;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use InvalidArgumentException;
use LogicException;

final readonly class ExecutionTargetPolicy
{
    /** @var Closure(ActionEnvelope, mixed): array<string, mixed> */
    private Closure $identityResolver;

    /** @var null|Closure(ActionEnvelope, mixed): mixed */
    private ?Closure $refreshResolver;

    /**
     * @param  callable(ActionEnvelope, mixed): array<string, mixed>  $identityUsing
     * @param  null|callable(ActionEnvelope, mixed): mixed  $refreshUsing
     */
    private function __construct(
        public string $name,
        public ExecutionTargetStrategy $strategy,
        callable $identityUsing,
        ?callable $refreshUsing,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('An execution-target policy must have a name.');
        }

        $this->identityResolver = Closure::fromCallable($identityUsing);
        $this->refreshResolver = $refreshUsing === null ? null : Closure::fromCallable($refreshUsing);
    }

    /**
     * @param  callable(ActionEnvelope, mixed): array<string, mixed>  $identityUsing
     * @param  callable(ActionEnvelope, mixed): mixed  $refreshUsing
     */
    public static function refresh(
        string $name,
        callable $identityUsing,
        callable $refreshUsing,
    ): self {
        return new self($name, ExecutionTargetStrategy::Refresh, $identityUsing, $refreshUsing);
    }

    /** @param callable(ActionEnvelope, mixed): array<string, mixed> $identityUsing */
    public static function acceptStaleSnapshot(string $name, callable $identityUsing): self
    {
        return new self($name, ExecutionTargetStrategy::AcceptStaleSnapshot, $identityUsing, null);
    }

    /** @return array<string, mixed> */
    public function identity(ActionEnvelope $envelope, mixed $target): array
    {
        $identity = $this->resolveIdentity($envelope, $target);

        if (! is_array($identity) || array_is_list($identity)) {
            throw new LogicException('An execution-target identity must be an associative array.');
        }

        $this->assertCanonical($identity);

        return $identity;
    }

    private function resolveIdentity(ActionEnvelope $envelope, mixed $target): mixed
    {
        return ($this->identityResolver)($envelope, $target);
    }

    public function targetForExecution(ActionEnvelope $envelope, mixed $proposalTarget): mixed
    {
        if ($this->strategy === ExecutionTargetStrategy::AcceptStaleSnapshot) {
            return $proposalTarget;
        }

        if ($this->refreshResolver === null) {
            throw new LogicException('A refresh execution-target policy must define a refresh resolver.');
        }

        return ($this->refreshResolver)($envelope, $proposalTarget);
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
            throw new LogicException('An execution-target identity may only contain arrays, scalar values, and null.');
        }
    }
}
