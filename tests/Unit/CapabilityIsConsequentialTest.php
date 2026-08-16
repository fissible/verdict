<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;

/**
 * #194 follow-up. `isConsequential()` is the single home for "a capability whose decisions are
 * expensive to lose". Naming it collapses the enumeration that was duplicated across call sites, and
 * gives the concept a test so a future gate is a one-line change here rather than a silent escape.
 */
function isConsequentialCapability(): Capability
{
    return Capability::usingPolicy(
        name: 'orders.act',
        ability: 'act',
        resolveTarget: fn (ActionEnvelope $e): int => 1,
    );
}

it('is consequential when confirmation is required', function (): void {
    $capability = isConsequentialCapability()->requiresConfirmation(
        bindUsing: fn (ActionEnvelope $e, int $t): array => ['id' => $t],
    );

    expect($capability->isConsequential())->toBeTrue();
});

it('is consequential when at-most-once execution is required', function (): void {
    $capability = isConsequentialCapability()->atMostOnce(ExecutionClaimPolicy::named(
        'once',
        fn (ActionEnvelope $e, int $t): array => ['id' => $t],
    ));

    expect($capability->isConsequential())->toBeTrue();
});

it('is not consequential for an ordinary capability', function (): void {
    expect(isConsequentialCapability()->executeUsing(fn (): string => 'ok')->isConsequential())->toBeFalse();
});
