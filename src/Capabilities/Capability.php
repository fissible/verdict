<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Closure;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use InvalidArgumentException;
use LogicException;

final readonly class Capability
{
    /**
     * Which shape this closure takes depends on {@see $targetSource}: a context-resolved capability
     * hands it an ActionContext so the proposal is not in scope, a proposal-resolved one hands it
     * the whole envelope. See ADR 0025.
     *
     * @var (Closure(ActionEnvelope): mixed)|(Closure(ActionContext): mixed)
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
     * SHA-256 over the canonical form of the security-material declared configuration (ADR 0017
     * Decision §1) — capability name/ability, confirmation requirement/TTL, execution-target
     * policy name/strategy, rate-limit policy name/limit/window, execution-claim policy name, and
     * the optional configurationVersion. Deliberately excludes closures (not meaningfully
     * serializable — ADR 0017 "Alternatives rejected") and non-material operator-facing strings
     * (confirmation/rate-limit reason). Computed once, on the fully composed immutable capability,
     * during registration — not recomputed on every configurationFingerprint() call. Because
     * Capability is immutable and every with-style method returns a new instance carrying the
     * complete prior state, the constructor call reached by CapabilityRegistry::register() always
     * receives the fully composed configuration, so computing it in the constructor is equivalent
     * to computing it at registration.
     */
    private string $configurationFingerprint;

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    /**
     * @param  (callable(ActionEnvelope): mixed)|(callable(ActionContext): mixed)  $resolveTarget
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
        private ?string $configurationVersion = null,
        public TargetSource $targetSource = TargetSource::Proposal,
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

        $this->configurationFingerprint = ArgumentFingerprint::make($this->declaredConfiguration());
    }

    /**
     * The readable, security-material configuration identified by configurationFingerprint().
     *
     * Closures and operator-facing reason strings are deliberately absent: they are not part of
     * the content-addressed configuration identity defined by ADR 0017.
     *
     * @return array<string, mixed>
     */
    public function declaredConfiguration(): array
    {
        return [
            'name' => $this->name,
            'ability' => $this->ability,
            'confirmation_required' => $this->approvalBindingResolver !== null,
            'confirmation_ttl_seconds' => $this->confirmationTtlSeconds,
            'execution_target_policy' => $this->executionTargetPolicy === null ? null : [
                'name' => $this->executionTargetPolicy->name,
                'strategy' => $this->executionTargetPolicy->strategy->value,
            ],
            'rate_limit_policy' => $this->rateLimitPolicy === null ? null : [
                'name' => $this->rateLimitPolicy->name,
                'limit' => $this->rateLimitPolicy->limit,
                'window_seconds' => $this->rateLimitPolicy->windowSeconds,
            ],
            'execution_claim_policy' => $this->executionClaimPolicy === null ? null : [
                'name' => $this->executionClaimPolicy->name,
            ],
            'configuration_version' => $this->configurationVersion,
        ];
    }

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    public static function usingPolicy(string $name, string $ability, callable $resolveTarget): self
    {
        return new self($name, $ability, $resolveTarget);
    }

    /**
     * A capability whose target is resolved from application context, not from the model's proposal.
     *
     * `$resolveTarget` receives an {@see ActionContext}, so the proposal is not in scope and cannot
     * be read. That is the point: an injected instruction can influence *whether* an action is
     * proposed, but not *which record* it acts on. The guarantee is enforced by the parameter type
     * rather than declared, because a declaration would still receive the envelope and could be
     * contradicted on the next line — see
     * [ADR 0025](../../docs/adr/0025-target-provenance-is-proven-where-it-can-be.md).
     *
     * The executor is unaffected and still receives the full `AuthorizedAction`; only target
     * *selection* is bounded.
     *
     * @param  callable(ActionContext): mixed  $resolveTarget
     */
    public static function usingPolicyForContextTarget(string $name, string $ability, callable $resolveTarget): self
    {
        return new self($name, $ability, $resolveTarget, targetSource: TargetSource::Context);
    }

    public function resolveTarget(ActionEnvelope $envelope): mixed
    {
        return $this->targetSource === TargetSource::Context
            ? ($this->targetResolver)($envelope->context)
            : ($this->targetResolver)($envelope);
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
            configurationVersion: $this->configurationVersion,
            targetSource: $this->targetSource,
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
            configurationVersion: $this->configurationVersion,
            targetSource: $this->targetSource,
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
            configurationVersion: $this->configurationVersion,
            targetSource: $this->targetSource,
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
            configurationVersion: $this->configurationVersion,
            targetSource: $this->targetSource,
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
            configurationVersion: $this->configurationVersion,
            targetSource: $this->targetSource,
        );
    }

    public function executionTargetPolicy(): ?ExecutionTargetPolicy
    {
        return $this->executionTargetPolicy;
    }

    /**
     * Pin an application-owned granularity marker (e.g. a deploy SHA) into the configuration
     * fingerprint, for changes to resolver/executor closure logic the fingerprint cannot see by
     * itself (ADR 0017 Decision §1's stated residual gap).
     */
    public function configurationVersion(string $version): self
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
            executionTargetPolicy: $this->executionTargetPolicy,
            configurationVersion: $version,
        );
    }

    public function configurationFingerprint(): string
    {
        return $this->configurationFingerprint;
    }

    public function configuration(): CapabilityConfiguration
    {
        return new CapabilityConfiguration(
            fingerprint: $this->configurationFingerprint,
            capability: $this->name,
            declared: $this->declaredConfiguration(),
        );
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
