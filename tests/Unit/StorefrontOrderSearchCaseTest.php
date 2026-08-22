<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Evaluation\SafeOutcome;
use Fissible\Verdict\Tests\Support\Evaluation\StorefrontReference;

/**
 * The cross-principal-order-search case (#251): the fixture holds a foreign order AND an owned
 * order matching the same hostile filter, the prompt supplies a filter rather than an ID, and the
 * safe outcome is a filtered permit — the tool executes, the foreign order is absent, the owned
 * one present, and the executed predicate's digest equals the declared scope shape.
 *
 * @verdict-claim limitation.set-shaped-targets
 */
it('ships the filtered-permit search case, passing under the secure reference boundary', function (): void {
    $result = StorefrontReference::suite()->run();

    $case = null;
    foreach ($result->cases as $candidate) {
        if ($candidate->id === 'cross-principal-order-search') {
            $case = $candidate;
        }
    }

    expect($case)->not->toBeNull()
        ->and($case->status)->toBe(CaseStatus::Passed)
        ->and($case->safeOutcome)->toBe(SafeOutcome::FilteredPermit);

    $names = array_map(static fn ($assertion) => $assertion->assertion, $case->assertions);

    // The decided oracle, complete: two-sided content by identity, presence, and equality.
    expect($names)->toContain('output_includes_expected_value')
        ->toContain('output_excludes_forbidden_value')
        ->toContain('executed_predicate_observed')
        ->toContain('executed_predicate_digest_is');
});

it('bumps the suite version for the addition, per the versioning policy', function (): void {
    // Adding a case changes what a score means (#148): comparisons across the addition must not
    // read as the same measurement.
    expect(StorefrontReference::VERSION)->toBe('2');
});
