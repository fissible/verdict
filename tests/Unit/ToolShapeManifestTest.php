<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\DeclaresExpressibleToolShapes;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\AccountRecoveryAttackPack;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\EvaluationReport;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\RagBorneInjectionAttackPack;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\StorefrontAttackPack;
use Fissible\Verdict\Evaluation\ToolIntegrityAttackPack;
use Fissible\Verdict\Evaluation\ToolShape;

/**
 * The machine-readable coverage declaration (#251, round-2 amendment 3): pack versioning makes
 * additions visible, but says nothing about absence — a reader should not need to diff pack
 * versions to learn that no case exercises set-returning tools. Each pack declares the tool
 * shapes it can express, and run output surfaces the declaration beside the existing coverage
 * reporting, expressible and not-expressible both.
 */
function manifestSuite(?array $toolShapes): SecuritySuite
{
    return new SecuritySuite(
        name: 'manifest-suite',
        version: '1',
        cases: [
            EvaluationCase::attack(
                'manifest-case',
                '1',
                new CaseInput(trustedSetup: [], untrustedInput: []),
                static fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
                [Assertions::notExecuted()],
            ),
        ],
        toolShapes: $toolShapes,
    );
}

it('declares expressible tool shapes on every shipped pack', function (): void {
    expect(StorefrontAttackPack::class)->toImplement(DeclaresExpressibleToolShapes::class)
        ->and(AccountRecoveryAttackPack::class)->toImplement(DeclaresExpressibleToolShapes::class)
        ->and(RagBorneInjectionAttackPack::class)->toImplement(DeclaresExpressibleToolShapes::class)
        ->and(ToolIntegrityAttackPack::class)->toImplement(DeclaresExpressibleToolShapes::class);
});

it('surfaces the declaration in the report, expressible and not-expressible both', function (): void {
    $report = json_decode(
        (new EvaluationReport(manifestSuite([ToolShape::RecordKeyed])->run()))->toJson(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report['tool_shapes']['expressible'])->toBe(['record-keyed'])
        ->and($report['tool_shapes']['not_expressible'])->toBe(['single-scalar-target', 'set-returning']);
});

it('omits the manifest from a suite that declares none, rather than claiming an empty one', function (): void {
    // A suite built without a declaration predates the manifest, or its pack never declared —
    // absence of the field is honest; an empty "expressible" list would claim the pack measured
    // that nothing is expressible.
    $report = json_decode(
        (new EvaluationReport(manifestSuite(null)->run()))->toJson(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report)->not->toHaveKey('tool_shapes');
});

it('rejects a declaration holding anything but tool shapes', function (): void {
    manifestSuite(['record-keyed']);
})->throws(InvalidArgumentException::class);
