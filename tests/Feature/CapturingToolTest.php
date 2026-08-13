<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CapturingTool;
use Fissible\Verdict\Evaluation\LiveObservationUnavailable;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class CapturingToolOrder
{
    public function __construct(public int $id, public int $customerId) {}
}

final class CapturingToolDefinition implements Tool
{
    public int $invocations = 0;

    public function description(): Stringable|string
    {
        return 'Cancel an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        return 'executed';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * A hand-rolled `Approvable&Tool` used only where the JSON envelope is fabricated directly, to pin
 * `CapturingTool`'s parsing of a malformed decision envelope. This is deliberately not a `BoundTool`:
 * every assertion about the real envelope shape and the `Approvable` passthrough uses a genuine
 * `BoundTool` obtained from `VerdictManager::bound()` elsewhere in this file.
 */
final class MalformedEnvelopeTool implements Approvable, Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        return '{"status":"not_executed"}';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    public function withoutApproval(): static
    {
        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        return null;
    }
}

/**
 * Same rationale as `MalformedEnvelopeTool`: a real `BoundTool` always sources its `decision` from a
 * backed enum's `->value` (see `AbstractVerdictTool::handle()`), so it structurally cannot return a
 * `decision` string that no `Disposition` case matches. Only a fake can reach that branch.
 */
final class UnrecognizedDecisionTool implements Approvable, Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order.';
    }

    public function handle(Request $request): Stringable|string
    {
        return '{"status":"not_executed","decision":"bogus"}';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    public function withoutApproval(): static
    {
        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        return null;
    }
}

function capturingToolDenyingCapability(string $name, int &$executorCalls): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): CapturingToolOrder => new CapturingToolOrder(
            (int) $envelope->proposal->arguments['order_id'],
            72,
        ),
    )->executionTarget(acceptTestSnapshot('capturing-tool-deny-snapshot'))
        ->executeUsing(function (AuthorizedAction $action) use (&$executorCalls): string {
            $executorCalls++;

            return 'executed';
        });
}

function capturingToolConfirmationCapability(string $name): Capability
{
    return Capability::usingPolicy(
        name: $name,
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope): CapturingToolOrder => new CapturingToolOrder(
            (int) $envelope->proposal->arguments['order_id'],
            72,
        ),
    )->requiresConfirmation(
        bindUsing: fn (ActionEnvelope $envelope, CapturingToolOrder $order): array => [
            'actor_id' => $envelope->context->actor,
            'order_id' => $order->id,
        ],
        reason: 'Confirm cancellation of this order.',
    )->executionTarget(acceptTestSnapshot('capturing-tool-confirmation-snapshot'))
        ->executeUsing(fn (AuthorizedAction $action): string => 'executed');
}

it('records a real BoundTool denial without reaching the executor', function (): void {
    $executorCalls = 0;
    $definition = new CapturingToolDefinition;

    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Authority does not permit this action.');
        }
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(capturingToolDenyingCapability('orders.cancel', $executorCalls));

    $capture = new LiveToolCapture;
    $tool = new CapturingTool(
        $verdict->bound($definition, 'orders.cancel', new ActionContext('customer-72')),
        'orders.cancel',
        $capture,
    );

    $result = json_decode((string) $tool->handle(new Request(['order_id' => 1001], 'call-1')), true, flags: JSON_THROW_ON_ERROR);

    expect($executorCalls)->toBe(0)
        ->and($definition->invocations)->toBe(0)
        ->and($result['status'])->toBe('not_executed')
        ->and($capture->toolObservations())->toHaveCount(1)
        ->and($capture->toolObservations()[0]->capability)->toBe('orders.cancel')
        ->and($capture->toolObservations()[0]->disposition)->toBe(Disposition::Deny)
        ->and($capture->toolObservations()[0]->executed)->toBeFalse();
});

it('keeps the wrapped tool approvable so the preflight still runs', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(capturingToolConfirmationCapability('orders.confirm'));

    $tool = new CapturingTool(
        $verdict->bound(new CapturingToolDefinition, 'orders.confirm', new ActionContext('customer-72')),
        'orders.confirm',
        new LiveToolCapture,
    );

    expect($tool)->toBeInstanceOf(Approvable::class)
        ->and($tool->shouldRequestApproval(new Request(['order_id' => 1001], 'call-1')))
        ->toBeInstanceOf(Approval::class);
});

it('returns itself from the fluent approval methods', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });

    $verdict = app(VerdictManager::class);
    $verdict->capability(capturingToolConfirmationCapability('orders.fluent'));

    $tool = new CapturingTool(
        $verdict->bound(new CapturingToolDefinition, 'orders.fluent', new ActionContext('customer-72')),
        'orders.fluent',
        new LiveToolCapture,
    );

    expect($tool->requireApproval('because'))->toBe($tool)
        ->and($tool->withoutApproval())->toBe($tool);
});

it('reports a malformed decision envelope as an unavailable observation', function (): void {
    $tool = new CapturingTool(new MalformedEnvelopeTool, 'orders.cancel', new LiveToolCapture);

    expect(fn () => $tool->handle(new Request(['order_id' => 1001], 'call-1')))
        ->toThrow(LiveObservationUnavailable::class, 'decision envelope');
});

it('reports an unrecognized decision value as an unavailable observation', function (): void {
    $tool = new CapturingTool(new UnrecognizedDecisionTool, 'orders.cancel', new LiveToolCapture);

    expect(fn () => $tool->handle(new Request(['order_id' => 1001], 'call-1')))
        ->toThrow(LiveObservationUnavailable::class, 'unrecognized decision');
});
