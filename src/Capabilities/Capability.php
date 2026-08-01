<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use InvalidArgumentException;
use LogicException;

final readonly class Capability
{
    /**
     * @var Closure(ActionEnvelope): mixed
     */
    private Closure $targetResolver;

    /**
     * @var null|Closure(AuthorizedAction): mixed
     */
    private ?Closure $executor;

    /**
     * @var null|Closure(ActionEnvelope, mixed): array<string, mixed>
     */
    private ?Closure $approvalBindingResolver;

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    private function __construct(
        public string $name,
        public string $ability,
        callable $resolveTarget,
        ?callable $executor = null,
        ?callable $approvalBindingResolver = null,
        private ?string $confirmationReason = null,
        private ?int $confirmationTtlSeconds = null,
        private ?RateLimitPolicy $rateLimitPolicy = null,
        private ?ExecutionClaimPolicy $executionClaimPolicy = null,
        private ?ExecutionTargetPolicy $executionTargetPolicy = null,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A capability must have a name.');
        }

        if (trim($this->ability) === '') {
            throw new InvalidArgumentException('A capability must name a Laravel authorization ability.');
        }

        $this->targetResolver = Closure::fromCallable($resolveTarget);
        $this->executor = $executor === null ? null : Closure::fromCallable($executor);
        $this->approvalBindingResolver = $approvalBindingResolver === null
            ? null
            : Closure::fromCallable($approvalBindingResolver);
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

    /**
     * @param  callable(AuthorizedAction): mixed  $executor
     */
    public function executeUsing(callable $executor): self
    {
        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $executor,
            approvalBindingResolver: $this->approvalBindingResolver,
            confirmationReason: $this->confirmationReason,
            confirmationTtlSeconds: $this->confirmationTtlSeconds,
            rateLimitPolicy: $this->rateLimitPolicy,
            executionClaimPolicy: $this->executionClaimPolicy,
            executionTargetPolicy: $this->executionTargetPolicy,
        );
    }

    /**
     * Require an explicit approval bound to trusted, application-defined resource identity.
     *
     * @param  callable(ActionEnvelope, mixed): array<string, mixed>  $bindUsing
     */
    public function requiresConfirmation(
        callable $bindUsing,
        ?string $reason = null,
        ?int $ttlSeconds = null,
    ): self {
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            throw new InvalidArgumentException('A confirmation TTL must be at least one second.');
        }

        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $this->executor,
            approvalBindingResolver: $bindUsing,
            confirmationReason: $reason,
            confirmationTtlSeconds: $ttlSeconds,
            rateLimitPolicy: $this->rateLimitPolicy,
            executionClaimPolicy: $this->executionClaimPolicy,
            executionTargetPolicy: $this->executionTargetPolicy,
        );
    }

    public function rateLimit(RateLimitPolicy $policy): self
    {
        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $this->executor,
            approvalBindingResolver: $this->approvalBindingResolver,
            confirmationReason: $this->confirmationReason,
            confirmationTtlSeconds: $this->confirmationTtlSeconds,
            rateLimitPolicy: $policy,
            executionClaimPolicy: $this->executionClaimPolicy,
            executionTargetPolicy: $this->executionTargetPolicy,
        );
    }

    public function rateLimitPolicy(): ?RateLimitPolicy
    {
        return $this->rateLimitPolicy;
    }

    public function atMostOnce(ExecutionClaimPolicy $policy): self
    {
        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $this->executor,
            approvalBindingResolver: $this->approvalBindingResolver,
            confirmationReason: $this->confirmationReason,
            confirmationTtlSeconds: $this->confirmationTtlSeconds,
            rateLimitPolicy: $this->rateLimitPolicy,
            executionClaimPolicy: $policy,
            executionTargetPolicy: $this->executionTargetPolicy,
        );
    }

    public function executionClaimPolicy(): ?ExecutionClaimPolicy
    {
        return $this->executionClaimPolicy;
    }

    public function executionTarget(ExecutionTargetPolicy $policy): self
    {
        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $this->executor,
            approvalBindingResolver: $this->approvalBindingResolver,
            confirmationReason: $this->confirmationReason,
            confirmationTtlSeconds: $this->confirmationTtlSeconds,
            rateLimitPolicy: $this->rateLimitPolicy,
            executionClaimPolicy: $this->executionClaimPolicy,
            executionTargetPolicy: $policy,
        );
    }

    public function executionTargetPolicy(): ?ExecutionTargetPolicy
    {
        return $this->executionTargetPolicy;
    }

    public function confirmationRequired(): bool
    {
        return $this->approvalBindingResolver !== null;
    }

    public function confirmationReason(): ?string
    {
        return $this->confirmationReason;
    }

    public function confirmationTtlSeconds(): ?int
    {
        return $this->confirmationTtlSeconds;
    }

    /** @return array<string, mixed> */
    public function approvalBinding(ActionEnvelope $envelope, mixed $target): array
    {
        if ($this->approvalBindingResolver === null) {
            throw new LogicException('The capability does not define an approval binding.');
        }

        $binding = ($this->approvalBindingResolver)($envelope, $target);

        if (! is_array($binding) || array_is_list($binding)) {
            throw new LogicException('A capability approval binding must be an associative array.');
        }

        $this->assertCanonicalBinding($binding);

        return $binding;
    }

    private function assertCanonicalBinding(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertCanonicalBinding($item);
            }

            return;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw new LogicException('A capability approval binding may only contain arrays, scalar values, and null.');
        }
    }

    public function isExecutable(): bool
    {
        return $this->executor !== null;
    }

    public function execute(AuthorizedAction $action): mixed
    {
        if ($this->executor === null) {
            throw CapabilityNotExecutable::named($this->name);
        }

        if ($action->capability !== $this) {
            throw new LogicException('An authorized action may only be executed by its bound capability.');
        }

        return ($this->executor)($action);
    }
}
