<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceDigest;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Targets\ResourceProjection;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The capture digests the projection the capability DECLARED, and captures nothing at all when
 * none was declared (#366).
 *
 * tests/Unit/ResourceProjectionTest.php pins what a declaration is; every value there is formed by
 * calling the declaration directly, so nothing in that file shows the capture ever consulted one.
 * This file drives real executions through `VerdictManager` and reads a sink the test owns, which
 * is the only way to contradict a capture that ignored the declaration and kept inferring.
 *
 * WHAT WOULD PASS WITHOUT THIS FILE. A declaration that returns exactly what inference used to
 * return — `Order::disclosure()`, say — is satisfied by an implementation that never reads the
 * declaration at all. Every test here is written so that inference and declaration DISAGREE:
 * targets carry public properties the declaration omits, two capabilities declare different
 * projections of one target, and one capability declares nothing where inference would have
 * produced a digest anyway.
 *
 * WHY THE EXPECTATION IS DERIVED FROM THE DECLARATION. Calling `$projection->project()` to build the
 * expected digest is not the tautology the workbench swap case has to avoid. There, deriving the
 * expectation from the executor's own path would prove nothing about the CONTENT, so the content is
 * hand-written. Here the declaration IS the contract under test — it is the sole source of truth for
 * which bytes matter — so agreement with it is exactly the claim, and the target it is applied to is
 * a value this file constructed and can therefore vouch for.
 */
function declaredProjectionContext(): ActionContext
{
    return new ActionContext(actor: 41, metadata: ['tenant_id' => 'tenant-a']);
}

function declaredProjectionEnvelope(string $capability): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal($capability, ['record_id' => 55]),
        declaredProjectionContext(),
    );
}

function permitDeclaredProjection(Container $app): void
{
    $app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

/**
 * Every capability in this file identifies the SAME logical record, so identity can never be what
 * separates two observations. Whatever distinguishes them below is the projection, which is the
 * point of the file.
 */
function declaredProjectionPolicy(object $refreshed, int $recordId = 55): ExecutionTargetPolicy
{
    return ExecutionTargetPolicy::refresh(
        name: 'declared-projection-record',
        identityUsing: fn (ActionEnvelope $envelope, object $target): array => [
            'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
            'resource_type' => 'record',
            'resource_id' => $recordId,
        ],
        refreshUsing: fn (ActionEnvelope $envelope, object $proposalTarget): object => $refreshed,
    );
}

/** @return array<string, mixed> */
function declaredProjectionIdentity(int $recordId = 55): array
{
    return ['tenant_id' => 'tenant-a', 'resource_type' => 'record', 'resource_id' => $recordId];
}

/** Registration only: nothing here computes, reports, or remembers an expected value. */
function registerDeclaredProjectionCapability(
    string $name,
    object $proposalTarget,
    ?object $refreshed = null,
    ?ResourceProjection $projection = null,
    int $recordId = 55,
): void {
    $capability = Capability::usingPolicy($name, 'view', fn (): object => $proposalTarget);

    if ($refreshed !== null) {
        $capability = $capability->executionTarget(declaredProjectionPolicy($refreshed, $recordId));
    }

    if ($projection !== null) {
        $capability = $capability->resourceProjection($projection);
    }

    app(VerdictManager::class)->capability(
        $capability->executeUsing(fn (AuthorizedAction $action): string => 'read'),
    );
}

function declaredProjectionSink(string $checkpoint = 'record-body'): LiveToolCapture
{
    $sink = new LiveToolCapture;

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, $checkpoint));

    return $sink;
}

it('digests the declared projection of the refreshed target, and records its contract', function (): void {
    permitDeclaredProjection($this->app);

    // The two targets carry the same identity and different bytes — ADR 0003 §7 denies an execution
    // whose refreshed target identifies a different record, so content is the only axis a
    // differential can move along.
    $proposalTarget = (object) ['ref' => 'REC-55', 'body' => 'as-proposed', 'noise' => 'ignored'];
    $refreshed = (object) ['ref' => 'REC-55', 'body' => 'as-refreshed', 'noise' => 'ignored'];

    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['ref' => $target->ref, 'body' => $target->body],
    );

    registerDeclaredProjectionCapability('records.declared', $proposalTarget, $refreshed, $projection);

    $sink = declaredProjectionSink();
    $envelope = declaredProjectionEnvelope('records.declared');
    $result = app(VerdictManager::class)->runBound($envelope);

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1);

    $observed = $sink->resources()[0];

    // Honours the declaration, applied to the refreshed target.
    expect($observed->digest)->toBe(ResourceDigest::for($projection->project($envelope, $refreshed)))
        // ADR 0003 makes the refreshed target the one the executor acts on, so it is the one that
        // must be measured. A capture that projected the proposal target would land here instead.
        ->and($observed->digest)->not->toBe(ResourceDigest::for($projection->project($envelope, $proposalTarget)));

    // The contract travels with the observation, because it is what decides comparability later.
    expect($observed->projection)->toBe('record-body/v1');
});

it('does not move the digest when a field the declaration omits changes', function (): void {
    // The false-positive route #366 opens with. `noise` is a public property of the target and is
    // not declared, so it must be invisible to the digest. Under inference — `get_object_vars()`,
    // or an `Arrayable` that returns everything — these two runs would disagree, and a case built on
    // this resource would report a swap that never happened.
    permitDeclaredProjection($this->app);

    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['ref' => $target->ref, 'body' => $target->body],
    );

    $target = (object) ['ref' => 'REC-55', 'body' => 'stable', 'noise' => 'first'];
    registerDeclaredProjectionCapability('records.quiet', $target, $target, $projection);

    $noisy = (object) ['ref' => 'REC-55', 'body' => 'stable', 'noise' => 'second-and-different'];
    registerDeclaredProjectionCapability('records.noisy', $noisy, $noisy, $projection);

    $sink = declaredProjectionSink();

    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.quiet'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.noisy'));

    expect($sink->resources())->toHaveCount(2)
        ->and($sink->resources()[1]->digest)->toBe($sink->resources()[0]->digest);
});

it('gives two capabilities declaring different projections of one target different digests', function (): void {
    // The acceptance criterion in the shape the identity differential already uses: an
    // implementation that ignored the declaration and read the target's shape would produce ONE
    // digest here, because there is only one target and one shape.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55', 'body' => 'shared', 'status' => 'open'];

    $body = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );
    $status = ResourceProjection::declared(
        'record-status/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['status' => $target->status],
    );

    registerDeclaredProjectionCapability('records.by-body', $target, $target, $body);
    registerDeclaredProjectionCapability('records.by-status', $target, $target, $status);

    $sink = declaredProjectionSink();

    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.by-body'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.by-status'));

    expect($sink->resources())->toHaveCount(2)
        ->and($sink->resources()[0]->digest)->not->toBe($sink->resources()[1]->digest)
        ->and($sink->resources()[0]->resourceIdentity)->toBe($sink->resources()[1]->resourceIdentity)
        ->and($sink->resources()[0]->projection)->toBe('record-body/v1')
        ->and($sink->resources()[1]->projection)->toBe('record-status/v1');
});

it('observes nothing for a capability that declares no projection', function (): void {
    // Inference is removed, not demoted to a fallback: a target that used to yield a digest through
    // `get_object_vars()` now yields none. The comparison consequently reports unmeasured, which
    // this codebase treats as the honest outcome rather than a pass.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55', 'body' => 'inferrable'];
    registerDeclaredProjectionCapability('records.undeclared', $target, $target);

    $sink = declaredProjectionSink();
    $result = app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.undeclared'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toBe([])
        // The capture is also what records the execution these observations pair against. With no
        // measurement to pair, it must record no execution either — a synthetic tool observation
        // asserting a Permit the capture never evaluated is a fact nobody established.
        ->and($sink->toolObservations())->toBe([]);
});

it('observes nothing for a capability that declares a projection but no execution target', function (): void {
    // A projection with no policy has no identity to pair on, so there is nothing to record. It must
    // land as unmeasured rather than as an exception the capture swallows on the way to the
    // executor — the outcome is the same for the run, and only one of them is deliberate.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55', 'body' => 'no-policy'];
    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );

    registerDeclaredProjectionCapability('records.policyless', $target, null, $projection);

    $sink = declaredProjectionSink();
    $result = app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.policyless'));

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});

it('leaves the execution unharmed when the declaration itself fails', function (): void {
    // An evaluation instrument must never break the thing it measures. A declaration that throws, or
    // returns a shape the digest cannot accept, makes the run unmeasured — never failed.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55'];

    registerDeclaredProjectionCapability('records.throwing', $target, $target, ResourceProjection::declared(
        'record-body/v1',
        function (ActionEnvelope $envelope, object $target): array {
            throw new RuntimeException('the declaration could not read the record');
        },
    ));

    registerDeclaredProjectionCapability('records.listing', $target, $target, ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['a', 'b'],
    ));

    $sink = declaredProjectionSink();

    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.throwing'))->executed)->toBeTrue();
    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.listing'))->executed)->toBeTrue();

    expect($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});

it('counts occurrences within a contract, so an interleaved contract cannot renumber a pair', function (): void {
    // The two capabilities identify the same record under the same checkpoint and differ only in
    // what they project. If occurrences were counted per checkpoint and identity alone, the second
    // `record-body/v1` observation would be occurrence 3, and a case declaring the natural 1 -> 2
    // pair would find one endpoint and land unmeasured — a detector defeated by an unrelated
    // capability happening to run in between.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55', 'body' => 'stable', 'status' => 'open'];

    $body = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );
    $status = ResourceProjection::declared(
        'record-status/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['status' => $target->status],
    );

    registerDeclaredProjectionCapability('records.paired', $target, $target, $body);
    registerDeclaredProjectionCapability('records.interleaved', $target, $target, $status);

    $sink = declaredProjectionSink();

    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.paired'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.interleaved'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.paired'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.interleaved'));

    $occurrences = array_map(
        fn ($resource): array => [$resource->projection, $resource->occurrence],
        $sink->resources(),
    );

    expect($occurrences)->toBe([
        ['record-body/v1', 1],
        ['record-status/v1', 1],
        ['record-body/v1', 2],
        ['record-status/v1', 2],
    ]);

    $identity = ResourceIdentity::for(declaredProjectionIdentity());

    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );

    // Measurable, and passing: the two `record-body/v1` endpoints projected equal bytes.
    expect(Assertions::resourceDigestMatchesPriorObservation('record-body', $identity, 'record-body/v1', 1, 2)
        ->evaluate($observation)->passed)->toBeTrue();
});

it('refuses to pair endpoints projected under different contracts', function (): void {
    // Where the false positive would have landed. Two capabilities read the same record, declare
    // different bytes, and their digests differ for a reason that is not a swap. A comparison naming
    // one contract finds one endpoint under it, so the case is unmeasured rather than a detection
    // the run never earned.
    permitDeclaredProjection($this->app);

    $target = (object) ['ref' => 'REC-55', 'body' => 'shared', 'status' => 'open'];

    registerDeclaredProjectionCapability('records.checked', $target, $target, ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    ));
    registerDeclaredProjectionCapability('records.used', $target, $target, ResourceProjection::declared(
        'record-status/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['status' => $target->status],
    ));

    $sink = declaredProjectionSink();

    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.checked'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.used'));

    $identity = ResourceIdentity::for(declaredProjectionIdentity());

    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );

    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('record-body', $identity, 'record-body/v1', 1, 2)
        ->evaluate($observation))->toThrow(CapabilityNotAttempted::class, 'fewer than two comparable');
});

it('counts occurrences within a resource, so a second record cannot renumber a pair', function (): void {
    // The other dimension of the same key. Both capabilities declare the SAME checkpoint and the
    // SAME contract, and differ only in which record they identify. An occurrence counter keyed on
    // checkpoint and contract alone — the shape that satisfies the interleaving test above — would
    // number these 1, 2, 3 and leave the pair over record 55 unpairable.
    permitDeclaredProjection($this->app);

    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );

    $first = (object) ['ref' => 'REC-55', 'body' => 'first-record'];
    $second = (object) ['ref' => 'REC-77', 'body' => 'second-record'];

    registerDeclaredProjectionCapability('records.first', $first, $first, $projection);
    registerDeclaredProjectionCapability('records.second', $second, $second, $projection, recordId: 77);

    $sink = declaredProjectionSink();

    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.first'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.second'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.first'));

    $occurrences = array_map(
        fn ($resource): array => [$resource->resourceIdentity, $resource->occurrence],
        $sink->resources(),
    );

    expect($occurrences)->toBe([
        [ResourceIdentity::for(declaredProjectionIdentity(55)), 1],
        [ResourceIdentity::for(declaredProjectionIdentity(77)), 1],
        [ResourceIdentity::for(declaredProjectionIdentity(55)), 2],
    ]);
});

it('counts occurrences within a checkpoint, so two captures over one sink do not renumber each other', function (): void {
    // A case may measure a resource at more than one checkpoint — a disclosure and a shipment view,
    // say — and each checkpoint's endpoints are numbered on their own terms. Two captures writing to
    // one sink is how a harness expresses that, so neither may see the other's count.
    //
    // Read this together with the test below it. The counter belongs to the SINK, not to a capture
    // instance, so this pair is what makes the checkpoint part of the key load-bearing: here two
    // checkpoints must not share a count, and below two captures at ONE checkpoint must.
    permitDeclaredProjection($this->app);

    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );

    $target = (object) ['ref' => 'REC-55', 'body' => 'stable'];
    registerDeclaredProjectionCapability('records.two-checkpoints', $target, $target, $projection);

    $sink = new LiveToolCapture;

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'record-body'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.two-checkpoints'));

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'record-shipment'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.two-checkpoints'));

    $occurrences = array_map(
        fn ($resource): array => [$resource->checkpoint, $resource->occurrence],
        $sink->resources(),
    );

    expect($occurrences)->toBe([['record-body', 1], ['record-shipment', 1]]);
});

it('keeps counting across two captures at the same checkpoint, rather than starting over', function (): void {
    // The hole that per-instance counting leaves, and the reason the count belongs to the sink. A
    // harness that rebinds the capture between phases — a fresh instance, the same checkpoint, the
    // same sink — would otherwise emit two endpoints both numbered 1 over one resource. The
    // assertion indexes its candidates by occurrence, so one would silently overwrite the other and
    // the case would compare a pair it never observed, with nothing anywhere reporting a problem.
    permitDeclaredProjection($this->app);

    $projection = ResourceProjection::declared(
        'record-body/v1',
        fn (ActionEnvelope $envelope, object $target): array => ['body' => $target->body],
    );

    $target = (object) ['ref' => 'REC-55', 'body' => 'stable'];
    registerDeclaredProjectionCapability('records.rebound', $target, $target, $projection);

    $sink = new LiveToolCapture;

    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'record-body'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.rebound'));

    // A different instance, deliberately: same checkpoint, same resource, same contract, same sink.
    app()->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'record-body'));
    app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.rebound'));

    expect(array_map(fn ($resource): int => $resource->occurrence, $sink->resources()))->toBe([1, 2]);

    // And the pair is expressible as the natural 1 -> 2, which is the whole point of numbering them.
    $observation = new Observation(
        disposition: null,
        executed: true,
        toolCalls: $sink->toolObservations(),
        resources: $sink->resources(),
    );

    expect(Assertions::resourceDigestMatchesPriorObservation('record-body', ResourceIdentity::for(declaredProjectionIdentity()), 'record-body/v1', 1, 2)
        ->evaluate($observation)->passed)->toBeTrue();
});

it('does not fall back to a shape it can read when no projection is declared', function (): void {
    // Inference is DELETED, not demoted. The undeclared case above used a plain object, which only
    // rules out the `get_object_vars()` branch; these two targets are exactly the shapes the other
    // two branches recognised. `Arrayable` was the first branch tried, and `disclosure()` was a
    // workbench fixture convention that had leaked into src/ — an adopter naming a method that way
    // had no way to know it changed what Verdict digested.
    permitDeclaredProjection($this->app);

    $arrayable = new class implements Arrayable
    {
        public string $body = 'arrayable';

        /** @return array<string, mixed> */
        public function toArray(): array
        {
            return ['body' => $this->body];
        }
    };

    $disclosing = new class
    {
        public string $body = 'disclosing';

        /** @return array<string, mixed> */
        public function disclosure(): array
        {
            return ['body' => $this->body];
        }
    };

    registerDeclaredProjectionCapability('records.arrayable', $arrayable, $arrayable);
    registerDeclaredProjectionCapability('records.disclosing', $disclosing, $disclosing);

    $sink = declaredProjectionSink();

    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.arrayable'))->executed)->toBeTrue();
    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.disclosing'))->executed)->toBeTrue();

    expect($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});

it('does not fall back to a readable shape when the declaration fails', function (): void {
    // The subtler fallback, and the one a passing suite would otherwise permit: keep inference, but
    // only reach for it when the declaration throws. Both targets below have a shape the deleted
    // branches could have read, and both declarations fail — so a digest appearing here is
    // inference surviving under a rescue clause.
    permitDeclaredProjection($this->app);

    $arrayable = new class implements Arrayable
    {
        public string $body = 'arrayable';

        /** @return array<string, mixed> */
        public function toArray(): array
        {
            return ['body' => $this->body];
        }
    };

    $disclosing = new class
    {
        public string $body = 'disclosing';

        /** @return array<string, mixed> */
        public function disclosure(): array
        {
            return ['body' => $this->body];
        }
    };

    $failing = ResourceProjection::declared('record-body/v1', function (ActionEnvelope $envelope, object $target): array {
        throw new RuntimeException('the declaration could not read the record');
    });

    registerDeclaredProjectionCapability('records.arrayable-failing', $arrayable, $arrayable, $failing);
    registerDeclaredProjectionCapability('records.disclosing-failing', $disclosing, $disclosing, $failing);

    $sink = declaredProjectionSink();

    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.arrayable-failing'))->executed)->toBeTrue();
    expect(app(VerdictManager::class)->runBound(declaredProjectionEnvelope('records.disclosing-failing'))->executed)->toBeTrue();

    expect($sink->resources())->toBe([])
        ->and($sink->toolObservations())->toBe([]);
});
