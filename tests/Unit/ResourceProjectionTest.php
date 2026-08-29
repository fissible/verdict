<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Targets\ResourceProjection;

/**
 * What a capability DECLARES when it says which bytes of its execution target matter (#366).
 *
 * The mechanism shipped in #295 inferred this: `Arrayable`, then a `disclosure()` method, then
 * public properties. Three things were wrong with that. An unrelated public property added to a
 * target moved every digest, so a case reported a swap nobody performed. Inference cannot know
 * which bytes the later action actually uses, so it digested whatever the object exposed. And
 * `disclosure()` was a workbench fixture convention that had leaked into `src/` — nothing told an
 * adopter that naming a method that way silently changed what Verdict digested.
 *
 * A declaration answers all three, and this file pins the declaration alone: the shape it accepts,
 * the contract identifier that decides which observations are comparable, and the values it
 * refuses. Whether the CAPTURE honours it is a different question, answered in
 * tests/Feature/ResourceProjectionIsDeclaredTest.php against real executions.
 *
 * WHY IT LIVES IN Targets\ AND NOT Evaluation\. A projection is a statement about an execution
 * target, declared beside `ExecutionTargetPolicy` on the capability that owns it. Putting it in the
 * evaluation namespace would make `Capability` — a production type — import the evaluation surface
 * to describe its own target.
 *
 * WHY IT IS NOT ON `ExecutionTargetPolicy`, where identity lives. One policy instance is shared by
 * many capabilities: the workbench attaches `orderTargetPolicy()` to six of them, whose executors
 * use materially different fields of the same order. Identity is a property of the RESOURCE and is
 * rightly shared; the bytes an action depends on are a property of the CAPABILITY and are not.
 */
function projectionEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('records.read', ['record_id' => 7]),
        new ActionContext(actor: 41, metadata: ['tenant_id' => 'tenant-a']),
    );
}

it('projects exactly what the declaration returns, and nothing the target also exposes', function (): void {
    // The defect this issue exists to close, in one assertion: `noise` is a public property of the
    // target and is absent from the declaration, so it is absent from the projection. Under
    // inference it would have been digested, and adding it later would have moved every digest of
    // every case built on this target.
    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['ref' => $target->ref, 'body' => $target->body],
    );

    $target = (object) ['ref' => 'REC-7', 'body' => 'as-refreshed', 'noise' => 'irrelevant'];

    expect($projection->project(projectionEnvelope(), $target))
        ->toBe(['ref' => 'REC-7', 'body' => 'as-refreshed']);
});

it('passes the envelope and the target it was given to the declaration', function (): void {
    // A declaration that could not see the envelope could not project a value the request decided —
    // a region, a tenant, a requested revision — and would be limited to the target's own fields.
    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => [
            'ref' => $target->ref,
            'tenant' => $envelope->context->metadata['tenant_id'] ?? null,
        ],
    );

    expect($projection->project(projectionEnvelope(), (object) ['ref' => 'REC-7']))
        ->toBe(['ref' => 'REC-7', 'tenant' => 'tenant-a']);
});

it('names a contract, because the contract is what decides comparability', function (): void {
    // Two capabilities can project the same resource differently and both be right — a reader needs
    // the fields it displays, an action needs the fields it depends on. Comparing one against the
    // other reports a swap that never happened. The contract identifier is how a case says which
    // declaration it is comparing, so mismatched projections land unmeasured instead.
    expect(ResourceProjection::declared('order-disclosure/v1', fn (): array => [])->contract)
        ->toBe('order-disclosure/v1');

    // Surrounding whitespace is stripped rather than carried, because this string is a PAIRING KEY:
    // 'order/v1' and ' order/v1 ' must not become two contracts that silently refuse to compare.
    expect(ResourceProjection::declared("  order-disclosure/v1\n", fn (): array => [])->contract)
        ->toBe('order-disclosure/v1');
});

it('refuses a contract that names nothing', function (): void {
    expect(fn () => ResourceProjection::declared('', fn (): array => []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ResourceProjection::declared("  \t ", fn (): array => []))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a projection that is not an associative array', function (): void {
    // The same rule `ExecutionTargetPolicy::identity()` applies, and the same exception class, for
    // the same reason: the value is canonicalized and hashed, and a list's digest depends on an
    // ordering the declaration never stated.
    $list = ResourceProjection::declared('record-body/v1', fn (): array => ['a', 'b']);

    expect(fn () => $list->project(projectionEnvelope(), (object) []))
        ->toThrow(LogicException::class);
});

it('refuses a projection carrying values that cannot be canonicalized', function (): void {
    // An object in the projection would be digested through whatever CanonicalJson happened to make
    // of it — the inference defect, one level down. The declaration must reduce the target to
    // scalars itself, so what is hashed is what was written.
    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['ref' => $target, 'body' => 'x'],
    );

    expect(fn () => $projection->project(projectionEnvelope(), (object) ['ref' => 'REC-7']))
        ->toThrow(LogicException::class);
});

it('accepts nested arrays, scalars and null, which is what a canonical projection is', function (): void {
    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (): array => ['ref' => 'REC-7', 'lines' => [['sku' => 'A', 'qty' => 2]], 'void' => null, 'open' => false],
    );

    expect($projection->project(projectionEnvelope(), (object) []))
        ->toBe(['ref' => 'REC-7', 'lines' => [['sku' => 'A', 'qty' => 2]], 'void' => null, 'open' => false]);
});
