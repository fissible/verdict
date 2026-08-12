<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\VerdictManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JsonException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Stringable;

abstract class AbstractVerdictTool implements Approvable, Tool
{
    /**
     * @var Closure(Request): mixed
     */
    private Closure $contextResolver;

    private Approval|false|null $approvalRequirement = null;

    private string $configuredDescriptionFingerprint;

    private ?string $invocationDescriptionFingerprint = null;

    /**
     * @param  ActionContext|callable(Request): mixed  $context
     * @param  InvocationContext|null  $invocations  Optional only for direct construction outside
     *                                               the container; without an active scoped frame,
     *                                               callable contexts deliberately resolve fresh.
     */
    public function __construct(
        private readonly Tool $tool,
        private readonly string $capability,
        ActionContext|callable $context,
        private readonly VerdictManager $verdict,
        private readonly string $deniedMessage = 'This action was not authorized.',
        private readonly ?InvocationContext $invocations = null,
        private readonly ?ApprovalExecutionContext $approvalExecutions = null,
    ) {
        $this->contextResolver = $context instanceof ActionContext
            ? static fn (Request $request): ActionContext => $context
            : Closure::fromCallable($context);
        $this->configuredDescriptionFingerprint = ContentFingerprint::make((string) $this->tool->description());
    }

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        $description = $this->tool->description();
        $this->invocationDescriptionFingerprint = ContentFingerprint::make((string) $description);

        return $description;
    }

    public function configuredDescriptionFingerprint(): string
    {
        return $this->configuredDescriptionFingerprint;
    }

    public function invocationDescriptionFingerprint(): ?string
    {
        return $this->invocationDescriptionFingerprint;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    /**
     * @throws JsonException
     */
    final public function handle(Request $request): Stringable|string
    {
        $toolCallId = $request->toolCallId() ?? '';
        $prepared = $this->invocations()->takePreparedEnvelope($toolCallId, $request->all());
        $envelope = ! $this->approvalExecutions()->allows($toolCallId)
            ? ($prepared ?? $this->envelope($request))
            : $this->envelope($request);

        $result = $this->executeAction($envelope, $request);

        if ($result->executed) {
            if (! is_string($result->output) && ! $result->output instanceof Stringable) {
                throw new LogicException('A Verdict Laravel AI tool must return a string or Stringable result.');
            }

            return $result->output;
        }

        return json_encode([
            'status' => 'not_executed',
            'capability' => $this->capability,
            'decision' => $result->evaluation->decision->disposition->value,
            'message' => $this->deniedMessage,
        ], JSON_THROW_ON_ERROR);
    }

    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvalRequirement = false;

        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        $envelope = $this->supportsVerifiedConfirmation() ? $this->envelope($request) : null;
        $decision = $envelope === null ? null : $this->verdict->requestConfirmation($envelope);

        if ($decision !== null) {
            return Approval::required($decision->reason);
        }

        if ($this->approvalRequirement !== false && $this->approvalRequirement instanceof Approval) {
            return $this->approvalRequirement;
        }

        $approval = $this->approvalRequirement === false
            ? null
            : ($this->tool instanceof Approvable ? $this->tool->shouldRequestApproval($request) : null);

        if ($approval === null && $envelope !== null) {
            $this->invocations()->rememberPreparedEnvelope($request->toolCallId() ?? '', $request->all(), $envelope);
        }

        return $approval;
    }

    final protected function definition(): Tool
    {
        return $this->tool;
    }

    final protected function verdict(): VerdictManager
    {
        return $this->verdict;
    }

    protected function supportsVerifiedConfirmation(): bool
    {
        return false;
    }

    private function invocations(): InvocationContext
    {
        return $this->invocations ?? Container::getInstance()->make(InvocationContext::class);
    }

    private function approvalExecutions(): ApprovalExecutionContext
    {
        return $this->approvalExecutions ?? Container::getInstance()->make(ApprovalExecutionContext::class);
    }

    private function envelope(Request $request): ActionEnvelope
    {
        $context = ($this->contextResolver)($request);

        if (! $context instanceof ActionContext) {
            throw new LogicException('A Verdict tool context resolver must return an ActionContext.');
        }

        return ActionEnvelope::wrap(
            proposal: new ActionProposal(
                capability: $this->capability,
                arguments: $request->all(),
                idempotencyKey: $request->toolCallId(),
                metadata: ['transport' => 'laravel-ai', 'tool_kind' => $this->toolKind()],
            ),
            context: $context,
        );
    }

    /**
     * Identifies which concrete Verdict Laravel AI tool primitive produced an evaluation, per
     * ADR 0005 and issue #15 — not settable by application input, so `GuardedTool` migration debt
     * stays auditable in evidence without a caller being able to spoof it.
     */
    abstract protected function toolKind(): string;

    abstract protected function executeAction(ActionEnvelope $envelope, Request $request): ExecutionResult;
}
