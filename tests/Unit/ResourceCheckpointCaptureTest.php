<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;

/**
 * The capture's own refusals, asserted against the capture rather than through `VerdictManager`.
 *
 * tests/Feature/ResourceProjectionIsDeclaredTest.php shows that an undeclared or policyless
 * capability produces no observation during a real run — but the manager also decides whether to
 * call the capture at all, so those tests cannot say WHICH of the two made the decision. If the
 * refusal lives only in the manager, then any other caller — a harness, a future seam, an adopter
 * wiring the capture directly — gets an exception or a fabricated observation instead of an honest
 * silence. So the contract is pinned here too, on the object that publishes it.
 */
function checkpointCaptureEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('records.read', ['record_id' => 55]),
        new ActionContext(actor: 41, metadata: ['tenant_id' => 'tenant-a']),
    );
}

function checkpointCapturePolicy(): ExecutionTargetPolicy
{
    return ExecutionTargetPolicy::refresh(
        name: 'capture-record',
        identityUsing: fn (ActionEnvelope $envelope, object $target): array => ['resource_id' => 55],
        refreshUsing: fn (ActionEnvelope $envelope, object $target): object => $target,
    );
}

it('names its checkpoint, because an unnamed one pairs with anything', function (): void {
    expect(fn () => new ResourceCheckpointCapture(new LiveToolCapture, ' '))
        ->toThrow(InvalidArgumentException::class);
});

it('records nothing for a capability that declares no projection', function (): void {
    $sink = new LiveToolCapture;
    $capture = new ResourceCheckpointCapture($sink, 'record-body');

    $capability = Capability::usingPolicy('records.read', 'view', fn (): object => (object) ['body' => 'x'])
        ->executionTarget(checkpointCapturePolicy());

    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);

    expect($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});

it('records nothing for a capability with no execution-target policy', function (): void {
    // There is no identity to pair on, so there is no measurement to make. It must be a deliberate
    // silence rather than a null reaching the identity call and being swallowed on the way out.
    $sink = new LiveToolCapture;
    $capture = new ResourceCheckpointCapture($sink, 'record-body');

    $capability = Capability::usingPolicy('records.read', 'view', fn (): object => (object) ['body' => 'x'])
        ->resourceProjection(ResourceProjection::declared(
            'record-body/v1',
            fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
        ));

    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);

    expect($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});

it('records an endpoint and its execution together, or neither', function (): void {
    // The positive control for the two refusals above: with both declarations present the capture
    // does record, so their emptiness is a fact about the declarations and not about a capture that
    // never works. Each observation is also tied to an execution the sink counted — a resource
    // endpoint with no execution behind it cannot be paired, by design.
    $sink = new LiveToolCapture;
    $capture = new ResourceCheckpointCapture($sink, 'record-body');

    $capability = Capability::usingPolicy('records.read', 'view', fn (): object => (object) ['body' => 'x'])
        ->executionTarget(checkpointCapturePolicy())
        ->resourceProjection(ResourceProjection::declared(
            'record-body/v1',
            fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
        ));

    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);

    expect($sink->resources())->toHaveCount(1)
        ->and($sink->toolObservations())->toHaveCount(1)
        ->and($sink->resources()[0]->projection)->toBe('record-body/v1')
        ->and($sink->resources()[0]->executionSequence)->toBe($sink->toolObservations()[0]->executionSequence);
});

it('starts occurrences over when the sink is reset', function (): void {
    // The counter is the SINK's, so it is bound by the sink's lifecycle. `reset()` exists because a
    // harness reuses one capture across trials; a count that survived it would number the second
    // trial's first endpoint 2, and a case declaring the natural 1 -> 2 pair would compare across
    // two runs — or find nothing at all.
    $sink = new LiveToolCapture;
    $capture = new ResourceCheckpointCapture($sink, 'record-body');

    $capability = Capability::usingPolicy('records.read', 'view', fn (): object => (object) ['body' => 'x'])
        ->executionTarget(checkpointCapturePolicy())
        ->resourceProjection(ResourceProjection::declared(
            'record-body/v1',
            fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
        ));

    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);
    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);

    expect(array_map(fn ($resource): int => $resource->occurrence, $sink->resources()))->toBe([1, 2]);

    $sink->reset();

    $capture->capture(checkpointCaptureEnvelope(), $capability, (object) ['body' => 'x']);

    expect(array_map(fn ($resource): int => $resource->occurrence, $sink->resources()))->toBe([1]);
});

it('keeps the three parts of an occurrence key separate, whatever the strings contain', function (): void {
    // Checkpoint and contract are adopter-authored strings; the identity between them is
    // scheme-tagged and cannot be forged. Joining the three with a delimiter would let a checkpoint
    // that happens to contain that delimiter — and a valid identity — impersonate another tuple, and
    // two unrelated resources would then share a count. The two tuples below are crafted against a
    // NUL join specifically, which is the delimiter the shipped capture used, and produce
    // byte-identical keys under it. They must still be counted apart. A different delimiter would
    // need a differently crafted pair, which is the argument for a nested map: it has no delimiter
    // to collide on, so the property holds by construction rather than by a rule to remember.
    $sink = new LiveToolCapture;

    $first = ResourceIdentity::for(['resource_id' => 1]);
    $second = ResourceIdentity::for(['resource_id' => 2]);

    expect($sink->nextResourceOccurrence("record-body\0".$first."\0order/v1", $second, 'record-status/v1'))->toBe(1)
        ->and($sink->nextResourceOccurrence('record-body', $first, "order/v1\0".$second."\0record-status/v1"))->toBe(1);
});
