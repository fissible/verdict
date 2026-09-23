<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Exceptions\UnsupportedApprovalDecision;
use Fissible\Verdict\LaravelAi\HasVerdictRunMiddleware;
use Fissible\Verdict\LaravelAi\LaravelApprovalDecisions;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\LaravelAi\VerdictRunIntegration;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall as StreamToolCall;
use Laravel\Ai\Tools\Request;

final class RunGateAgent implements Agent, Conversational, HasTools, HasVerdictRunMiddleware
{
    use Promptable;

    public array $history = [];

    public function __construct(public Tool $tool) {}

    public function instructions(): string
    {
        return 'Perform the protected operation.';
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return [$this->tool];
    }

    public function maxSteps(): int
    {
        return 3;
    }

    public function verdictRunMiddleware(): array
    {
        return [new VerdictProvenanceMiddleware(app(ProvenanceLedger::class), Trust::Untrusted, DataClass::Internal)];
    }
}

/** Only provider output is substituted: the SDK owns steps, pauses, tools, and resumption. */
final class RunGateGateway implements StepTextGateway
{
    public int $calls = 0;

    public bool $fail = false;

    public function __construct(public ?ToolCall $call) {}

    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('Gateway interrupted.');
        }

        return new StepResponse(
            text: 'done',
            toolCalls: $this->call === null ? [] : [$this->call],
            finishReason: $this->call === null ? FinishReason::Stop : FinishReason::ToolCalls,
            usage: new TextUsage,
            meta: new Meta($provider->name(), $model),
        );
    }

    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        yield (new StreamStart('start', $provider->name(), $model, time()))->withInvocationId($invocationId);

        $response = $this->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);

        foreach ($response->toolCalls as $call) {
            yield (new StreamToolCall('event-'.$call->id, $call, time()))->withInvocationId($invocationId);
        }

        return $response;
    }
}

function runGateTool(int &$executions, array &$observations): Tool
{
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });

    app(VerdictManager::class)->capability(
        Capability::usingPolicy('migration.run-gate', 'operate', fn (ActionEnvelope $e): array => $e->proposal->arguments)
            ->executionTarget(acceptTestSnapshot('migration-run-gate'))
            ->requiresConfirmation(fn (ActionEnvelope $e, array $target): array => $target)
            ->executeUsing(function () use (&$executions, &$observations): string {
                $executions++;
                $observations[] = [
                    app(ApprovalExecutionContext::class)->allows('run-call'),
                    app(InvocationContext::class)->current(),
                ];

                return 'executed';
            }),
    );

    return app(VerdictManager::class)->bound(new class implements Tool
    {
        public function name(): string
        {
            return 'RunGateTool';
        }

        public function description(): string
        {
            return 'A protected operation.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function handle(Request $request): string
        {
            throw new LogicException('Use the bound executor.');
        }
    }, 'migration.run-gate', new ActionContext('actor-1'));
}

it('installs run gates on every built-in text driver while preserving native provider capabilities', function (string $driver, string $class): void {
    $provider = Ai::textProvider($driver);

    expect($provider)->toBeInstanceOf($class)
        ->and($provider)->toBeInstanceOf(get_parent_class($class));

    foreach (class_implements(get_parent_class($class)) as $contract) {
        expect($provider)->toBeInstanceOf($contract);
    }

    $executions = 0;
    $observations = [];
    $agent = new RunGateAgent(runGateTool($executions, $observations));
    $provider->useTextGateway(new RunGateGateway(null));
    $response = $agent->prompt('record provenance', provider: $driver, model: 'test-model');
    expect(app(ProvenanceLedger::class)->forCorrelation($response->invocationId))->toHaveCount(1)
        ->and(app(InvocationContext::class)->current())->toBeNull();
})->with(array_map(fn (string $driver, string $class): array => [$driver, $class], array_keys(VerdictRunIntegration::PROVIDERS), array_values(VerdictRunIntegration::PROVIDERS)));

it('requires both the receipt and run frame when the real loop resumes', function (bool $stream, string $proof): void {
    $executions = 0;
    $observations = [];
    $tool = runGateTool($executions, $observations);
    $agent = new RunGateAgent($tool);
    $gateway = new RunGateGateway(new ToolCall('run-call', 'RunGateTool', ['id' => 1]));
    Ai::textProvider('openai')->useTextGateway($gateway);

    $paused = $agent->prompt('operate', provider: 'openai');
    expect($paused->pendingApprovals)->toHaveCount(1)->and($executions)->toBe(0);
    $agent->history = $paused->messages->all();
    $gateway->call = null;

    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall('run-call');
    expect($challenge)->not->toBeNull();

    if ($proof !== 'frame-only') {
        $approvals->approve($challenge->receiptId, 'run-call', 'human');
    }

    // Wildcard decisions reach the SDK resume, but intentionally grant no Verdict frame IDs.
    $decisions = $proof === 'receipt-only' ? AiDecision::approveAll() : Decisions::from(['run-call' => AiDecision::approve()]);
    $result = $stream ? $agent->stream($decisions, provider: 'openai') : $agent->prompt($decisions, provider: 'openai');

    if ($stream) {
        expect($executions)->toBe(0)
            ->and(app(ApprovalExecutionContext::class)->allows('run-call'))->toBeFalse()
            ->and(app(InvocationContext::class)->current())->toBeNull();
        iterator_to_array($result);
    }

    expect($executions)->toBe($proof === 'both' ? 1 : 0)
        ->and(app(ApprovalExecutionContext::class)->allows('run-call'))->toBeFalse()
        ->and(app(InvocationContext::class)->current())->toBeNull();

    if ($proof === 'both') {
        expect($observations)->toBe([[true, $result->invocationId]]);
    }

    // Replay the same original pending history. A consumed receipt must never execute twice.
    $replay = $stream ? $agent->stream($decisions, provider: 'openai') : $agent->prompt($decisions, provider: 'openai');
    if ($stream) {
        iterator_to_array($replay);
    }
    expect($executions)->toBe($proof === 'both' ? 1 : 0);
})->with([false, true])->with(['both', 'receipt-only', 'frame-only']);

it('enforces the same proofs for synchronous and streamed forward tool execution', function (bool $stream, string $proof): void {
    $executions = 0;
    $observations = [];
    $tool = runGateTool($executions, $observations);
    $request = new Request(['id' => 1], 'run-call');
    $tool->shouldRequestApproval($request);
    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall('run-call');
    if ($proof !== 'frame-only') {
        $approvals->approve($challenge->receiptId, 'run-call', 'human');
    }

    // Expose handle through a plain Tool to exercise the execution gate even when the SDK's
    // optional Approvable preflight is absent. The Verdict-bound kernel must still refuse.
    $forward = new class($tool) implements Tool
    {
        public function __construct(private Tool $bound) {}

        public function name(): string
        {
            return 'RunGateTool';
        }

        public function description(): string
        {
            return $this->bound->description();
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function handle(Request $request): Stringable|string
        {
            return $this->bound->handle($request);
        }
    };
    $agent = new RunGateAgent($forward);
    Ai::textProvider('openai')->useTextGateway(new RunGateGateway(new ToolCall('run-call', 'RunGateTool', ['id' => 1])));
    $run = function () use ($agent, $stream): void {
        if ($stream) {
            iterator_to_array($agent->stream('operate', provider: 'openai'));
        } else {
            $agent->prompt('operate', provider: 'openai');
        }
    };

    if ($proof === 'receipt-only') {
        $run();
    } else {
        app(ApprovalExecutionContext::class)->within(
            LaravelApprovalDecisions::approvedToolCalls(Decisions::from(['run-call' => AiDecision::approve()])),
            $run,
        );
    }

    // Gateway repeats the same call across steps: the receipt is consumed exactly once.
    expect($executions)->toBe($proof === 'both' ? 1 : 0)
        ->and(app(ApprovalExecutionContext::class)->allows('run-call'))->toBeFalse()
        ->and(app(InvocationContext::class)->current())->toBeNull();
    if ($proof === 'both') {
        expect($observations[0][0])->toBeTrue()->and($observations[0][1])->toBeString();
    }
})->with([false, true])->with(['both', 'receipt-only', 'frame-only']);

it('leaves no frame or execution on an unconsumed resumed stream', function (string $proof): void {
    $executions = 0;
    $observations = [];
    $tool = runGateTool($executions, $observations);
    $tool->shouldRequestApproval(new Request(['id' => 1], 'run-call'));
    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall('run-call');
    if ($proof !== 'frame-only') {
        $approvals->approve($challenge->receiptId, 'run-call', 'human');
    }
    $agent = new RunGateAgent($tool);
    $agent->history = [new AssistantMessage('', collect([new ToolCall('run-call', 'RunGateTool', ['id' => 1])]))];
    $gateway = new RunGateGateway(null);
    Ai::textProvider('openai')->useTextGateway($gateway);
    $decisions = $proof === 'receipt-only' ? AiDecision::approveAll() : Decisions::from(['run-call' => AiDecision::approve()]);
    $response = $agent->stream($decisions, provider: 'openai');

    expect($executions)->toBe(0)->and($gateway->calls)->toBe(0)
        ->and(app(ApprovalExecutionContext::class)->allows('run-call'))->toBeFalse()
        ->and(app(InvocationContext::class)->current())->toBeNull();
    expect((string) $tool->handle(new Request(['id' => 1], 'run-call')))->toContain('not_executed');
    unset($response);
    expect($executions)->toBe(0)->and(app(InvocationContext::class)->current())->toBeNull();
})->with(['both', 'receipt-only', 'frame-only']);

it('restores outer frames after real provider failure', function (bool $stream): void {
    $executions = 0;
    $observations = [];
    $tool = runGateTool($executions, $observations);
    $agent = new RunGateAgent($tool);
    $agent->history = [new AssistantMessage('', collect([new ToolCall('run-call', 'RunGateTool', ['id' => 1])]))];
    $gateway = new RunGateGateway(null);
    $gateway->fail = true;
    Ai::textProvider('openai')->useTextGateway($gateway);
    $decisions = Decisions::from(['run-call' => AiDecision::approve()]);
    $outer = LaravelApprovalDecisions::approvedToolCalls(Decisions::from(['outer-call' => AiDecision::approve()]));
    $context = app(ApprovalExecutionContext::class);
    $invocations = app(InvocationContext::class);
    $context->push($outer);
    $invocations->push('outer-invocation');

    try {
        expect(function () use ($agent, $stream, $decisions): void {
            if ($stream) {
                iterator_to_array($agent->stream($decisions, provider: 'openai'));
            } else {
                $agent->prompt($decisions, provider: 'openai');
            }
        })->toThrow(RuntimeException::class, 'Gateway interrupted.');
        expect($executions)->toBe(0)
            ->and($context->allows('run-call'))->toBeFalse()
            ->and($context->allows('outer-call'))->toBeTrue()
            ->and($invocations->current())->toBe('outer-invocation');
    } finally {
        $context->pop();
        $invocations->pop();
    }
})->with([false, true]);

it('rejects edited arguments at the real provider boundary before execution', function (bool $stream): void {
    $executions = 0;
    $observations = [];
    $agent = new RunGateAgent(runGateTool($executions, $observations));
    $gateway = new RunGateGateway(null);
    Ai::textProvider('openai')->useTextGateway($gateway);
    $decisions = Decisions::from(['run-call' => AiDecision::edit(['id' => 2])]);

    expect(fn () => $stream ? $agent->stream($decisions, provider: 'openai') : $agent->prompt($decisions, provider: 'openai'))
        ->toThrow(UnsupportedApprovalDecision::class);
    expect($gateway->calls)->toBe(0)->and($executions)->toBe(0)
        ->and(app(ApprovalExecutionContext::class)->allows('run-call'))->toBeFalse()
        ->and(app(InvocationContext::class)->current())->toBeNull();
})->with([false, true]);
