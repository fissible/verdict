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
use Fissible\Verdict\Intents\ActionIntent;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;
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
        private ?ResourceProjection $resourceProjection = null,
        private ?string $configurationVersion = null,
        private ?bool $requiresIntentRecord = null,
        public TargetSource $targetSource = TargetSource::Proposal,
        private ?Closure $approverSummaryDescriber = null,
        private bool $requiresAttestedIssuance = false,
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
     * the content-addressed configuration identity defined by ADR 0017. A resource projection is
     * also deliberately absent: it declares what an evaluation instrument measures, not
     * security-material configuration behind that identity.
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
            // Sparse deliberately: present only when the application declared a posture, so a
            // package upgrade alone changes no capability's content-addressed identity
            // (ADR 0017). A declared posture is security material and enters the fingerprint.
            ...($this->requiresIntentRecord === null
                ? []
                : ['requires_intent_record' => $this->requiresIntentRecord]),
            ...($this->requiresAttestedIssuance
                ? ['requires_attested_issuance' => true]
                : []),
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
        );
    }

    public function executionTargetPolicy(): ?ExecutionTargetPolicy
    {
        return $this->executionTargetPolicy;
    }

    public function resourceProjection(ResourceProjection $projection): self
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
            resourceProjection: $projection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
        );
    }

    public function declaredResourceProjection(): ?ResourceProjection
    {
        return $this->resourceProjection;
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $version,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
        );
    }

    /**
     * Declare this capability's write-ahead intent posture, overriding the global
     * `verdict.intents.required` lever in either direction (#160).
     *
     * With the requirement effective, no mutating gate runs until a durable {@see ActionIntent}
     * has been committed; an intent-write failure denies the action with nothing consumed.
     * Left undeclared, the capability follows the global lever.
     */
    public function requiresIntentRecord(bool $required = true): self
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $required,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
        );
    }

    /**
     * The declared intent-record posture: true or false when this capability declared one,
     * null when it follows the global `verdict.intents.required` lever.
     */
    public function intentRecordRequirement(): ?bool
    {
        return $this->requiresIntentRecord;
    }

    /**
     * Require a synchronous, tamper-evident attestation of the released approver summary before
     * Verdict persists a confirmation receipt or review request.
     */
    public function requiresAttestedIssuance(bool $required = true): self
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: $this->approverSummaryDescriber,
            requiresAttestedIssuance: $required,
        );
    }

    public function attestedIssuanceRequirement(): bool
    {
        return $this->requiresAttestedIssuance;
    }

    /**
     * @param  callable(ActionEnvelope, mixed, array<string, mixed>): string  $describe
     */
    public function describeForApprover(callable $describe): self
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
            resourceProjection: $this->resourceProjection,
            configurationVersion: $this->configurationVersion,
            requiresIntentRecord: $this->requiresIntentRecord,
            targetSource: $this->targetSource,
            approverSummaryDescriber: Closure::fromCallable($describe),
            requiresAttestedIssuance: $this->requiresAttestedIssuance,
        );
    }

    /** @param array<string, mixed> $binding */
    public function approverDescription(ActionEnvelope $envelope, mixed $target, array $binding): ?string
    {
        return $this->approverSummaryDescriber === null
            ? null
            : ($this->approverSummaryDescriber)($envelope, $target, $binding);
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

    /**
     * A capability whose decisions are expensive to lose: human approval or at-most-once execution.
     * These are the gates where an unrecorded decision means an approval nobody can later prove was
     * granted, or a claim whose admission history is unrecoverable.
     *
     * The single home for that predicate, so a future gate with the same property is added here
     * once rather than escaping an enumeration duplicated across call sites (the no-op-recorder
     * warning in `VerdictManager`, for one). See #194.
     */
    public function isConsequential(): bool
    {
        return $this->confirmationRequired() || $this->executionClaimPolicy() !== null;
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
