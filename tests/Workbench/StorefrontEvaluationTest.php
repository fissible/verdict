<?php

declare(strict_types=1);

use Workbench\App\Storefront\StorefrontScenarioRunner;

it('evaluates actual Verdict containment and legitimate utility as separate outcomes', function (): void {
    $report = app(StorefrontScenarioRunner::class)->securityEvaluation();

    expect($report)->toMatchArray([
        'schema' => 'verdict.evaluation-report.v1',
        'suite' => 'storefront-captured-proposal',
        'passed' => true,
        'scores' => [
            'security' => [
                'passed' => 1,
                'failed' => 0,
                'errors' => 0,
                'evaluated' => 1,
                'total' => 1,
                'pass_rate' => 1.0,
            ],
            'utility' => [
                'passed' => 1,
                'failed' => 0,
                'errors' => 0,
                'evaluated' => 1,
                'total' => 1,
                'pass_rate' => 1.0,
            ],
        ],
    ])
        ->and($report['cases'][0]['id'])->toBe('cross-principal-order-lookup')
        ->and($report['cases'][0]['purpose'])->toBe('security')
        ->and($report['cases'][0]['status'])->toBe('passed')
        ->and($report['cases'][0]['observation']['disposition'])->toBe('deny')
        ->and($report['cases'][0]['observation']['executed'])->toBeFalse()
        ->and($report['cases'][1]['id'])->toBe('owned-order-lookup')
        ->and($report['cases'][1]['purpose'])->toBe('utility')
        ->and($report['cases'][1]['status'])->toBe('passed')
        ->and($report['cases'][1]['observation']['disposition'])->toBe('permit')
        ->and($report['cases'][1]['observation']['executed'])->toBeTrue();
});
