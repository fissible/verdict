<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\BaselineComparator;
use Fissible\Verdict\Evaluation\BaselineComparison;
use Fissible\Verdict\Evaluation\EvaluationBaseline;
use Fissible\Verdict\Tests\Support\Evaluation\AccountRecoveryReference;
use Fissible\Verdict\Tests\Support\Evaluation\RagBorneInjectionReference;
use Fissible\Verdict\Tests\Support\Evaluation\StorefrontReference;
use Fissible\Verdict\Tests\Support\Evaluation\ToolIntegrityReference;
use PHPUnit\Framework\Assert;

it('matches the committed baseline for each shipped pack', function (string $reference): void {
    $path = dirname(__DIR__).'/Baselines/'.$reference::SUITE.'.json';

    Assert::assertFileExists(
        $path,
        'No committed baseline for this pack. Generate it with [composer evaluation:refresh-baselines] and commit the diff.',
    );

    $comparison = (new BaselineComparator)->compare(
        EvaluationBaseline::fromJson((string) file_get_contents($path)),
        $reference::suite()->run(),
    );

    $blocking = array_map(
        static fn ($change): string => sprintf(
            '%s: %s (%s -> %s)',
            $change->caseId,
            $change->kind->value,
            $change->baselineStatus?->value ?? 'missing',
            $change->currentStatus?->value ?? 'missing',
        ),
        array_values(array_filter(
            $comparison->changes,
            static fn ($change): bool => BaselineComparison::isBlocking($change->kind),
        )),
    );

    Assert::assertSame(
        [],
        $blocking,
        'The pack no longer matches its committed baseline. If the change is intentional and reviewed, '
        .'refresh with [composer evaluation:refresh-baselines] and commit the diff.',
    );
})->with([
    'account recovery' => [AccountRecoveryReference::class],
    'rag-borne injection' => [RagBorneInjectionReference::class],
    'storefront' => [StorefrontReference::class],
    'tool integrity' => [ToolIntegrityReference::class],
]);
