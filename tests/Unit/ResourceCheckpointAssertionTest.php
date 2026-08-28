<?php

declare(strict_types=1);

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ResourceDigest;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Evaluation\ResourceObservation;
use Fissible\Verdict\Evaluation\ToolObservation;

/**
 * What the check-to-use digest comparison MEANS once two endpoint captures exist (#295).
 *
 * Verdict decides every tool call at execution time, so the plan-time form of TOCTOU is already
 * defeated. What is unbound is the value-level gap: a resource is read and authorized against in one
 * call, and a later call acts on a resource an attacker swapped in the interval.
 *
 * WHAT THIS FILE CANNOT DO, stated first because it decides how much these tests are worth. Every
 * observation below is constructed by hand, so nothing here proves a capture ever ran, or that two
 * digests came from two real resolutions. A capture that read the resource once — after the swap —
 * and emitted that digest at both endpoints satisfies every test in this file.
 * `tests/Workbench/StorefrontCheckToUseSwapTest.php` is where that is ruled out, against the real
 * two-call flow with an independently built expectation. The two files are one specification and
 * neither is sufficient alone.
 *
 * WHAT THIS IS NOT. It detects; it does not prevent. The mechanism touches neither the boundary nor
 * the evidence schema, so it cannot refuse a use or record a denial. The observation channel is
 * assertion-only, like `ChallengeObservation` and `PredicateObservation`, and is never projected
 * into reports or baselines. Enforcement is a separate question with its own threat model.
 *
 * WHY PAIRING IS THE HARD PART. An assertion keyed on capability names is trivially satisfiable —
 * two calls of either capability make an existential equality true, and "same capability" is not
 * "same resource". Pairing is by RESOURCE IDENTITY, a NAMED CHECKPOINT, and DECLARED OCCURRENCES.
 * The occurrences are declared rather than inferred because the authorizing read is not always the
 * first: a list, then read, then act trajectory authorizes on its second observation, and an
 * assertion that assumed the first could not express that case at all.
 *
 * UNMEASURED IS NOT A PASS. Where a comparison cannot be made — no capture, one endpoint, a
 * different resource, a different checkpoint, an occurrence that never happened — the assertion
 * throws {@see CapabilityNotAttempted}, following `executedPredicateDigestIs`. Returning true would
 * report a swap-free run for a flow nobody observed; returning false would convict the boundary for
 * a harness omission. The message names the resource comparison rather than reusing the "capability
 * was never attempted" wording, which is false for a two-call run whose capture was not wired.
 *
 * WHERE IT EXPIRES. A match proves the harness observed equal declared projections at two points. It
 * says nothing about the interval between them — an ABA swap, D then D-prime then D again, matches,
 * and is undetectable from two endpoints by construction. It says nothing about row-level security,
 * views, triggers, a concurrent writer, or bytes below the capture boundary. Same rung, same
 * honesty, as the wire-SQL predicate ladder in docs/evaluation.md. The assertion is named for what
 * it proves — a match against a prior observation — not for the continuity it cannot establish.
 */
/**
 * Shaped like an `ExecutionTargetPolicy::identity()` result, because that is the only thing
 * `ResourceIdentity` accepts. Identity is the capability's own declaration, never a pair of loose
 * strings assembled at the call site — a two-argument form would let a capture invent an identity
 * shape instead of deriving one, which is the thing the differential in
 * tests/Feature/ResourceIdentityFollowsPolicyTest.php exists to forbid.
 */
function checkpointIdentity(int $orderId): string
{
    return ResourceIdentity::for([
        'tenant_id' => 'tenant-a',
        'resource_type' => 'order',
        'resource_id' => $orderId,
    ]);
}

function checkpointDigest(string $marker): string
{
    return ResourceDigest::SCHEME.':'.hash('sha256', $marker);
}

/**
 * The execution an observation came from is a SEQUENCE, not an argument fingerprint. Two calls of
 * the same capability with the same arguments necessarily share a fingerprint — the #187 differential
 * depends on exactly that — so a fingerprint cannot say which of them a projection was captured
 * during. The sink assigns the sequence as it observes executions.
 */
function checkpointObservation(
    string $checkpoint,
    string $identity,
    string $digest,
    int $occurrence,
    int $executionSequence = 1,
): ResourceObservation {
    return new ResourceObservation(
        checkpoint: $checkpoint,
        resourceIdentity: $identity,
        digest: $digest,
        occurrence: $occurrence,
        executionSequence: $executionSequence,
    );
}

function observationWithResources(array $resources): Observation
{
    return new Observation(
        disposition: Disposition::Permit,
        executed: true,
        toolCalls: [
            new ToolObservation('orders.view', hash('sha256', 'check'), Disposition::Permit, true, executionSequence: 1),
            new ToolObservation('orders.refresh-shipment', hash('sha256', 'use'), Disposition::Permit, true, executionSequence: 2),
            new ToolObservation('orders.view', hash('sha256', 'check'), Disposition::Permit, true, executionSequence: 3),
        ],
        resources: $resources,
    );
}

it('holds when the declared occurrences carry the same projection for the same resource', function (): void {
    $observation = observationWithResources([
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 2, 2),
    ]);

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate($observation)->passed)->toBeTrue();
});

it('fails when the projection moved between the declared occurrences', function (): void {
    // The attack: same resource by identity, different bytes at the later occurrence.
    $observation = observationWithResources([
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('after'), 2, 2),
    ]);

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate($observation)->passed)->toBeFalse();
});

it('compares the occurrences the case declared, not whichever are adjacent', function (): void {
    // A list, then read, then act trajectory: the authorizing read is occurrence 2, and the swap
    // lands between 2 and 3. An assertion hardwired to the first and last would compare a listing
    // against the use and report a mismatch that says nothing about the authorization.
    $observation = observationWithResources([
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('listing'), 1),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 2, 2),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('after'), 3, 3),
    ]);

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 2, 3)
        ->evaluate($observation)->passed)->toBeFalse();

    // Two DISTINCT occurrences that agree still hold. Selecting one observation twice would not:
    // a comparison of a value with itself is true for any value and proves no second endpoint
    // existed, which is why equal selectors are refused below.
    $unmoved = observationWithResources([
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 2, 2),
    ]);

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate($unmoved)->passed)->toBeTrue();
});

it('reports an unwired capture as unmeasured, naming the resource comparison', function (): void {
    // #251 paid for this already: a harness that omits its wiring lands unmeasured rather than
    // silently passing. And the message has to be honest — for a two-call run with no capture,
    // "the capability was never attempted" is false and sends the reader to the wrong problem.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate(observationWithResources([])))
        ->toThrow(CapabilityNotAttempted::class, 'fewer than two comparable');
});

it('is unmeasured when a declared occurrence never happened', function (): void {
    // One endpoint is not a comparison. This is the shape of a run whose use never executed — a
    // self-declining model, or an unrelated denial — and calling it a pass would let a case claim a
    // detection it never performed.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate(observationWithResources([
            checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
        ])))->toThrow(CapabilityNotAttempted::class);
});

it('does not pair observations of different resources', function (): void {
    // Two records, equal bytes by coincidence. Pairing on checkpoint alone would read this as a
    // clean check-to-use flow for 1001, which was never used at all.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate(observationWithResources([
            checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('same'), 1),
            checkpointObservation('order-disclosure', checkpointIdentity(2002), checkpointDigest('same'), 2, 2),
        ])))->toThrow(CapabilityNotAttempted::class);
});

it('does not pair observations from different checkpoints', function (): void {
    // Two checkpoints select different bytes, so equality between them is meaningless and inequality
    // is not a swap.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate(observationWithResources([
            checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
            checkpointObservation('shipment-disclosure', checkpointIdentity(1001), checkpointDigest('after'), 2, 2),
        ])))->toThrow(CapabilityNotAttempted::class);
});

it('refuses selectors that name nothing, and occurrences that cannot exist', function (): void {
    // A blank selector matches everything or nothing depending on the flow rather than on intent.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('', checkpointIdentity(1001), 1, 2))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', ' ', 1, 2))
        ->toThrow(InvalidArgumentException::class);

    // Occurrences are 1-based positions in the observed order.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 0, 2))
        ->toThrow(InvalidArgumentException::class);

    // The check cannot follow the use; that is not a check-to-use comparison.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 3, 2))
        ->toThrow(InvalidArgumentException::class);

    // Nor can it BE the use. One observation compared with itself passes for any value, and proves
    // no second endpoint was ever captured.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 2, 2))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses identities and digests that are not scheme-tagged', function (): void {
    // These reach assertion failures and debug output. A scheme tag keeps a raw customer identifier
    // from being mistaken for an opaque fingerprint, and keeps a digest from being read as one
    // produced under a different algorithm or canonicalization — the rule PredicateDigest enforces.
    expect(fn () => checkpointObservation('order-disclosure', 'order:1001', checkpointDigest('x'), 1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => checkpointObservation('order-disclosure', checkpointIdentity(1001), 'deadbeef', 1))
        ->toThrow(InvalidArgumentException::class);
});

it('carries no resource content, only its digest and what pairs it', function (): void {
    // The rule PredicateObservation states: observations reach assertion failures and debug output,
    // and a resource projection can be a customer record. What travels is an irreversible digest
    // plus the identity, checkpoint, and occurrence needed to pair it.
    $observation = checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1);

    expect(array_keys(get_object_vars($observation)))
        ->toBe(['checkpoint', 'resourceIdentity', 'digest', 'occurrence', 'executionSequence']);
});

it('is unmeasured when an endpoint belongs to no executed tool call', function (): void {
    // Pairing two digests says nothing unless each was captured DURING one of the calls the case
    // claims. Without this, a capture emitting observations outside any execution — or a fixture
    // hand-building them — reads as a clean check-to-use flow that no call ever performed.
    // Sequence 99 is no execution the observation recorded.
    expect(fn () => Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate(observationWithResources([
            checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1),
            checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('after'), 2, 99),
        ])))->toThrow(CapabilityNotAttempted::class);
});

it('distinguishes two executions of the same capability with identical arguments', function (): void {
    // The case a fingerprint cannot express. Both reads are `orders.view` with the same arguments,
    // so they share an argument fingerprint by construction; only the execution sequence separates
    // them. A binding keyed on the fingerprint would treat these as one call and could pair a
    // projection with an execution that did not produce it.
    $observation = observationWithResources([
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('before'), 1, 1),
        checkpointObservation('order-disclosure', checkpointIdentity(1001), checkpointDigest('after'), 2, 3),
    ]);

    expect(Assertions::resourceDigestMatchesPriorObservation('order-disclosure', checkpointIdentity(1001), 1, 2)
        ->evaluate($observation)->passed)->toBeFalse();
});
