<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision as VerdictDecision;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;

final class ApprovalTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final readonly class ApprovalOrder
{
    public function __construct(public int $id, public int $customerId, public int $version = 1) {}
}

final class ApprovalDefinitionTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        throw new LogicException('The definition handler must never execute.');
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

beforeEach(function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): VerdictDecision
        {
            return VerdictDecision::permit();
        }
    });
});

/**
 * @param  array<int, ApprovalOrder>  $orders
 */
function approvalTool(array $orders, int &$executions, ?int $ttlSeconds = null): BoundTool
{
    $verdict = app(VerdictManager::class);
    $verdict->capability(
        Capability::usingPolicy(
            name: 'orders.cancel',
            ability: 'cancel',
            resolveTarget: fn (ActionEnvelope $envelope): ApprovalOrder => $orders[$envelope->proposal->arguments['order_id']],
        )->requiresConfirmation(
            bindUsing: fn (ActionEnvelope $envelope, ApprovalOrder $order): array => [
                'actor_id' => $envelope->context->actor,
                'order_id' => $order->id,
                'order_version' => $order->version,
            ],
            reason: 'Confirm cancellation of this order.',
            ttlSeconds: $ttlSeconds,
        )->executeUsing(function (AuthorizedAction $action) use (&$executions): string {
            $executions++;

            return 'cancelled';
        }),
    );

    return $verdict->bound(
        definition: new ApprovalDefinitionTool,
        capability: 'orders.cancel',
        context: new ActionContext(actor: 72),
    );
}

function approvalPrompt(Decisions $decisions): AgentPrompt
{
    return new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: '',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'test-model',
        approvalDecisions: $decisions,
    );
}

function executeWithinApprovalMiddleware(
    Tool $tool,
    Request $request,
    Decisions $decisions,
): string {
    return (string) app(VerdictApprovalMiddleware::class)->handle(
        approvalPrompt($decisions),
        fn (): Stringable|string => $tool->handle($request),
    );
}

it('requires an exact durable approval before executing and consumes it once', function (): void {
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions);
    $request = new Request(['order_id' => 1001], 'call-cancel-1001');

    $approval = $tool->shouldRequestApproval($request);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-cancel-1001');

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval?->reason)->toBe('Confirm cancellation of this order.')
        ->and($challenge)->not->toBeNull()
        ->and($challenge?->receiptId)->toHaveLength(64);

    $direct = json_decode((string) $tool->handle($request), true, flags: JSON_THROW_ON_ERROR);

    expect($direct['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(0);

    $approved = app(ApprovalManager::class)->approve(
        receiptId: $challenge->receiptId,
        toolCallId: $challenge->toolCallId,
        approvedBy: 'customer:72',
    );

    expect($approved->outcome)->toBe(ApprovalOutcome::Approved);

    $stillDirect = json_decode((string) $tool->handle($request), true, flags: JSON_THROW_ON_ERROR);

    expect($stillDirect['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(0);

    $decisions = Decisions::from(['call-cancel-1001' => Decision::approve()]);

    expect(executeWithinApprovalMiddleware($tool, $request, $decisions))->toBe('cancelled')
        ->and($executions)->toBe(1);

    $evidence = app(EvidenceRecorder::class);

    expect($evidence)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $evidence instanceof InMemoryEvidenceRecorder) {
        throw new LogicException('Expected the in-memory evidence recorder.');
    }

    $approvalEvidence = collect($evidence->all())->firstWhere('stage', 'approval');

    expect($approvalEvidence?->approvalReceiptFingerprint)
        ->toBe(hash('sha256', $challenge->receiptId));

    $replay = json_decode(
        executeWithinApprovalMiddleware($tool, $request, $decisions),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($replay['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(1)
        ->and(app(ApprovalReceiptStore::class)->findForToolCall('call-cancel-1001')?->status)
        ->toBe(ApprovalReceiptStatus::Consumed);
});

it('rejects changed arguments after approval', function (): void {
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions);
    $original = new Request(['order_id' => 1001, 'notify_customer' => false], 'call-cancel-changed');
    $tool->shouldRequestApproval($original);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-cancel-changed');

    expect($challenge)->not->toBeNull();

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'customer:72');
    $changed = new Request(['order_id' => 1001, 'notify_customer' => true], 'call-cancel-changed');
    $result = json_decode(
        executeWithinApprovalMiddleware(
            $tool,
            $changed,
            Decisions::from(['call-cancel-changed' => Decision::approve()]),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(0)
        ->and(app(ApprovalReceiptStore::class)->findForToolCall('call-cancel-changed')?->status)
        ->toBe(ApprovalReceiptStatus::Approved);
});

it('does not accept wildcard or edited Laravel approval decisions', function (Decisions $decisions): void {
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions);
    $request = new Request(['order_id' => 1001], 'call-cancel-explicit-only');
    $tool->shouldRequestApproval($request);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-cancel-explicit-only');

    expect($challenge)->not->toBeNull();

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'customer:72');
    $result = json_decode(
        executeWithinApprovalMiddleware($tool, $request, $decisions),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(0);
})->with([
    'wildcard approval' => fn (): Decisions => Decision::approveAll(),
    'edited arguments' => fn (): Decisions => Decisions::from([
        'call-cancel-explicit-only' => Decision::edit(['order_id' => 1001]),
    ]),
]);

it('does not execute after the approval receipt expires', function (): void {
    $clock = new ApprovalTestClock(new DateTimeImmutable('2026-08-01 12:00:00'));
    $this->app->instance(Clock::class, $clock);
    $this->app->forgetInstance(ApprovalManager::class);
    $this->app->forgetInstance(VerdictManager::class);
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions, ttlSeconds: 1);
    $request = new Request(['order_id' => 1001], 'call-cancel-expired');
    $tool->shouldRequestApproval($request);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-cancel-expired');

    expect($challenge)->not->toBeNull();

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'customer:72');
    $clock->time = $clock->time->modify('+2 seconds');
    $result = json_decode(
        executeWithinApprovalMiddleware(
            $tool,
            $request,
            Decisions::from(['call-cancel-expired' => Decision::approve()]),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result['decision'])->toBe('require_confirmation')
        ->and($executions)->toBe(0);
});

it('re-authorizes after approval and preserves an unused receipt when policy now denies', function (): void {
    $authorizer = new class implements CapabilityAuthorizer
    {
        public int $calls = 0;

        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): VerdictDecision
        {
            $this->calls++;

            return $this->calls === 1
                ? VerdictDecision::permit()
                : VerdictDecision::deny('Authority was revoked.');
        }
    };
    $this->app->instance(CapabilityAuthorizer::class, $authorizer);
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions);
    $request = new Request(['order_id' => 1001], 'call-cancel-revoked');
    $tool->shouldRequestApproval($request);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-cancel-revoked');

    expect($challenge)->not->toBeNull();

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'customer:72');
    $result = json_decode(
        executeWithinApprovalMiddleware(
            $tool,
            $request,
            Decisions::from(['call-cancel-revoked' => Decision::approve()]),
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result['decision'])->toBe('deny')
        ->and($executions)->toBe(0)
        ->and($authorizer->calls)->toBe(2)
        ->and(app(ApprovalReceiptStore::class)->findForToolCall('call-cancel-revoked')?->status)
        ->toBe(ApprovalReceiptStatus::Approved);
});
