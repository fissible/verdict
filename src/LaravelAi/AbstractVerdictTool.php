<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\VerdictManager;
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
     */
    public function __construct(
        private readonly Tool $tool,
        private readonly string $capability,
        ActionContext|callable $context,
        private readonly VerdictManager $verdict,
        private readonly string $deniedMessage = 'This action was not authorized.',
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
        $envelope = $this->envelope($request);

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
        $decision = $this->supportsVerifiedConfirmation()
            ? $this->verdict->requestConfirmation($this->envelope($request))
            : null;

        if ($decision !== null) {
            return Approval::required($decision->reason);
        }

        if ($this->approvalRequirement === false) {
            return null;
        }

        if ($this->approvalRequirement instanceof Approval) {
            return $this->approvalRequirement;
        }

        return $this->tool instanceof Approvable
            ? $this->tool->shouldRequestApproval($request)
            : null;
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
                metadata: ['transport' => 'laravel-ai'],
            ),
            context: $context,
        );
    }

    abstract protected function executeAction(ActionEnvelope $envelope, Request $request): ExecutionResult;
}
