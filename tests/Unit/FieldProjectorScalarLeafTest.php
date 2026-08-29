<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\FieldProjector;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Tests\Support\FrozenClock;

/**
 * #311 item 2 — a projected leaf that is neither scalar/null nor array (an object) survives
 * projection and merge, then throws deep inside CanonicalJson::normalize while ArgumentFingerprint
 * builds the *permitted* ContextReleaseEvidence — far from the field that caused it, and with a
 * message about canonicalization rather than which field is at fault. Projection is the right place
 * to reject it, naming the offending dotted path, before any evidence write.
 */
it('rejects a non-scalar leaf during projection, naming the dotted path', function (): void {
    $payload = ['user' => ['avatar' => new stdClass, 'name' => 'Ann']];

    expect(fn (): array => (new FieldProjector)->project($payload, ['user.avatar']))
        ->toThrow(InvalidArgumentException::class, 'user.avatar');
});

it('names the type and the scalar rule in the projection error, not just the path', function (): void {
    $payload = ['user' => ['avatar' => new stdClass]];

    try {
        (new FieldProjector)->project($payload, ['user.avatar']);
        $this->fail('Expected a non-scalar leaf to be rejected during projection.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('user.avatar')
            ->toContain('stdClass')
            ->toContain('scalar');
    }
});

it('names the concrete wildcard-expanded path of a non-scalar leaf', function (): void {
    // The wildcard resolves to numeric indices; the error must point at the real path (items.0.meta),
    // not the pattern, so an operator can find the field.
    $payload = ['items' => [['meta' => new stdClass], ['meta' => 'ok']]];

    expect(fn (): array => (new FieldProjector)->project($payload, ['items.*.meta']))
        ->toThrow(InvalidArgumentException::class, 'items.0.meta');
});

it('rejects a non-scalar leaf even when it sits directly at a top-level path', function (): void {
    $payload = ['blob' => new stdClass];

    expect(fn (): array => (new FieldProjector)->project($payload, ['blob']))
        ->toThrow(InvalidArgumentException::class, 'blob');
});

it('rejects a non-scalar descendant inside a selected subtree, not only a directly selected object', function (): void {
    // Selecting the parent path pulls the whole subtree; a validator that only inspects the terminal
    // selected value sees an array and waves it through. The object lives deeper.
    $payload = ['user' => ['profile' => ['avatar' => new stdClass, 'handle' => 'ann']]];

    expect(fn (): array => (new FieldProjector)->project($payload, ['user']))
        ->toThrow(InvalidArgumentException::class, 'user.profile.avatar');
});

it('rejects an object sibling within a selected subtree of otherwise-scalar fields', function (): void {
    $payload = ['user' => ['name' => 'Ann', 'avatar' => new stdClass]];

    expect(fn (): array => (new FieldProjector)->project($payload, ['user']))
        ->toThrow(InvalidArgumentException::class, 'user.avatar');
});

it('rejects an object inside a selected list, naming its numeric index', function (): void {
    $payload = ['items' => [new stdClass]];

    expect(fn (): array => (new FieldProjector)->project($payload, ['items']))
        ->toThrow(InvalidArgumentException::class, 'items.0');
});

it('permits scalar, null, and nested-scalar leaves unchanged', function (): void {
    $payload = [
        'name' => 'Ann',
        'age' => 41,
        'vip' => true,
        'score' => 9.5,
        'note' => null,
        'nested' => ['a' => 'x', 'b' => ['c' => 2]],
    ];

    $projected = (new FieldProjector)->project($payload, [
        'name', 'age', 'vip', 'score', 'note', 'nested.a', 'nested.b.c',
    ]);

    expect($projected)->toBe([
        'name' => 'Ann',
        'age' => 41,
        'vip' => true,
        'score' => 9.5,
        'note' => null,
        'nested' => ['a' => 'x', 'b' => ['c' => 2]],
    ]);
});

it('permits an empty-array leaf, which carries no non-scalar value', function (): void {
    $payload = ['tags' => []];

    expect((new FieldProjector)->project($payload, ['tags']))->toBe(['tags' => []]);
});

it('permits a selected subtree of nested scalar fields unchanged', function (): void {
    // The recursive rejection walk must not false-positive on a subtree that contains only scalars.
    $payload = ['nested' => ['a' => 'x', 'b' => ['c' => 2, 'd' => 'y']], 'other' => 1];

    expect((new FieldProjector)->project($payload, ['nested']))
        ->toBe(['nested' => ['a' => 'x', 'b' => ['c' => 2, 'd' => 'y']]]);
});

it('surfaces the projection rejection from a permitted release before writing any evidence', function (): void {
    // Locks the security-relevant ordering (finding 5): on a *permitted* route, a non-scalar leaf must
    // be rejected by projection before any ContextReleaseEvidence is written — so the failure names the
    // field and leaves no evidence trail for a release that never happened. Uses the canonical audiences
    // the policy is keyed to, so the route is genuinely permitted and projection is actually reached.
    $recorder = new InMemoryEvidenceRecorder;
    $clock = new FrozenClock;
    $policies = (new ReleasePolicyRegistry)->register(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted),
    );
    $manager = new ContextReleaseManager(
        $policies,
        new FieldProjector,
        $recorder,
        $clock,
        new InvocationContext,
        new ProvenanceLedger($recorder, $recorder, $clock),
    );

    $release = fn (): mixed => $manager->release(
        payload: ['user' => ['avatar' => new stdClass, 'name' => 'Ann']],
        source: ApproverAudience::source(),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        paths: ['user.avatar'],
        destination: ApproverAudience::destination(),
    );

    // The message must name the field path — today's canonicalization error names only the type, so
    // this fails until projection does the rejection.
    expect($release)->toThrow(InvalidArgumentException::class, 'user.avatar');
    // And nothing was recorded for the release that never completed.
    expect($recorder->releases())->toBe([]);
});
