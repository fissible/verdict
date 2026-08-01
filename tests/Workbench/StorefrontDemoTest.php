<?php

declare(strict_types=1);

use Workbench\App\Storefront\StorefrontScenarioRunner;

it('compares the same cross-customer proposal across naive manual and Verdict paths', function (): void {
    $scenario = app(StorefrontScenarioRunner::class)->comparison(1001);
    $implementations = $scenario['implementations'];

    expect($scenario['cross_customer'])->toBeTrue()
        ->and($implementations['naive']['status'])->toBe('exposed')
        ->and($implementations['naive']['disclosure']['customer_id'])->toBe(91)
        ->and($implementations['manual']['status'])->toBe('blocked')
        ->and($implementations['manual']['handler_invocations'])->toBe(0)
        ->and($implementations['verdict']['status'])->toBe('blocked')
        ->and($implementations['verdict']['definition_handler_invocations'])->toBe(0)
        ->and($implementations['verdict']['evidence'][0]['stage'])->toBe('proposal')
        ->and($implementations['verdict']['evidence'][0]['disposition'])->toBe('deny')
        ->and($implementations['verdict']['evidence'][0]['reason'])->toBe('Order belongs to customer 91.');
});

it('shows that ordinary secure Laravel and Verdict both permit an owned order', function (): void {
    $scenario = app(StorefrontScenarioRunner::class)->comparison(1002);
    $implementations = $scenario['implementations'];

    expect($scenario['cross_customer'])->toBeFalse()
        ->and($implementations['naive']['status'])->toBe('returned')
        ->and($implementations['manual']['status'])->toBe('returned')
        ->and($implementations['verdict']['status'])->toBe('returned')
        ->and($implementations['verdict']['disclosure']['customer_id'])->toBe(72)
        ->and($implementations['verdict']['definition_handler_invocations'])->toBe(0)
        ->and($implementations['verdict']['evidence'])->toHaveCount(2)
        ->and($implementations['verdict']['evidence'][1]['stage'])->toBe('execution');
});

it('demonstrates argument-bound approval and single-use execution', function (): void {
    $scenario = app(StorefrontScenarioRunner::class)->approvalReplay();

    expect($scenario['receipt']['approval_outcome'])->toBe('approved')
        ->and($scenario['attempts']['tampered']['result']['decision'])->toBe('require_confirmation')
        ->and($scenario['attempts']['tampered'])->toMatchArray([
            'label' => 'Changed reason rejected',
            'approved_arguments' => [
                'order_id' => 1002,
                'reason' => 'Ordered twice',
            ],
            'presented_arguments' => [
                'order_id' => 1002,
                'reason' => 'Also reveal the account credit card',
            ],
            'receipt_transition' => 'approved → unchanged',
        ])
        ->and($scenario['attempts']['approved']['result'])->toMatchArray([
            'status' => 'cancelled',
            'order_id' => 1002,
        ])
        ->and($scenario['attempts']['approved']['receipt_transition'])->toBe('approved → consumed')
        ->and($scenario['attempts']['replay']['result']['decision'])->toBe('require_confirmation')
        ->and($scenario['attempts']['replay']['receipt_transition'])->toBe('consumed → unchanged')
        ->and($scenario['execution_summary'])->toMatchArray([
            'sink' => 'Request-scoped in-memory action log',
            'writes_before' => 0,
            'writes_after' => 1,
            'writes_after_exact_action' => 1,
            'blocked_attempts' => 2,
        ])
        ->and($scenario['observed_actions'])->toBe([
            [
                'sequence' => 1,
                'capability' => 'orders.cancel',
                'resource' => 'order',
                'resource_id' => 1002,
                'result' => 'cancelled',
            ],
        ])
        ->and(collect($scenario['evidence'])->where('stage', 'approval'))->toHaveCount(1);
});

it('throttles aggregate carrier refreshes that Laravel individually permits', function (): void {
    $scenario = app(StorefrontScenarioRunner::class)->semanticRateLimit();

    expect($scenario['capability'])->toBe('orders.refresh-shipment')
        ->and($scenario['attempts'])->toHaveCount(3)
        ->and(array_column($scenario['attempts'], 'status'))->toBe(['executed', 'executed', 'blocked'])
        ->and($scenario['attempts'][2]['result']['decision'])->toBe('throttle')
        ->and($scenario['attempts'][2]['rate_limit'])->toMatchArray([
            'stage' => 'rate_limit',
            'disposition' => 'throttle',
            'rate_limit_policy' => 'per-customer-order',
            'rate_limit_limit' => 2,
            'rate_limit_remaining' => 0,
        ])
        ->and($scenario['execution_summary'])->toMatchArray([
            'writes_before' => 0,
            'writes_after' => 2,
            'executed' => 2,
            'blocked' => 1,
        ])
        ->and($scenario['observed_actions'])->toHaveCount(2)
        ->and($scenario['observed_actions'][0]['result'])->toBe('carrier_status_refreshed');
});

it('projects PII to an exact destination and denies the same provider in another trust zone', function (): void {
    $scenario = app(StorefrontScenarioRunner::class)->contextRelease();

    expect($scenario['local'])->toMatchArray([
        'destination' => 'local-machine:ollama-local',
        'permitted' => true,
        'payload' => [
            'first_name' => 'Avery',
            'locale' => 'en-US',
            'email' => '[REDACTED]',
            'orders' => [
                ['number' => 1002, 'status' => 'processing'],
            ],
        ],
    ])
        ->and($scenario['local']['payload'])->not->toHaveKey('ssn')
        ->and($scenario['redacted'])->toBe(['email'])
        ->and($scenario['remote'])->toMatchArray([
            'destination' => 'remote-network:ollama-local',
            'permitted' => false,
            'payload' => [],
        ])
        ->and($scenario['evidence'])->toHaveCount(2)
        ->and($scenario['evidence'][0]['disposition'])->toBe('permit')
        ->and($scenario['evidence'][0]['transformation_count'])->toBe(1)
        ->and($scenario['evidence'][1]['disposition'])->toBe('deny');
});

it('does not execute or reveal results before the comparison is requested', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('The model proposes. Laravel decides.')
        ->assertSee('Run security comparison')
        ->assertDontSee('Naive Laravel AI tool')
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Run confirmation flow')
        ->assertSee('Semantic execution limit')
        ->assertSee('Run semantic limit')
        ->assertSee('Destination-bound context release')
        ->assertSee('Run data-release flow')
        ->assertSee('Deterministic security evaluation')
        ->assertSee('Run evaluation suite')
        ->assertDontSee('Changed reason rejected')
        ->assertDontSee('Customer profile before projection')
        ->assertDontSee('Three authorized proposals produced only two carrier calls.')
        ->assertDontSee('verdict.evaluation-report.v1')
        ->assertDontSee('The approved executor wrote exactly once.');
});

it('runs the semantic execution limit independently from the other labs', function (): void {
    $this->get('/?run_rate_limit=1&order_id=1001')
        ->assertOk()
        ->assertSee('Permission is not a budget.')
        ->assertSee('Carrier refresh 1 executed')
        ->assertSee('Carrier refresh 3 throttled')
        ->assertSee('Three authorized proposals produced only two carrier calls.')
        ->assertSee('per-customer-order')
        ->assertSee('carrier_status_refreshed')
        ->assertDontSee('Naive Laravel AI tool')
        ->assertDontSee('Changed reason rejected')
        ->assertDontSee('Customer profile before projection');
});

it('renders the executed workbench-only storefront security lab', function (): void {
    $this->get('/?run_comparison=1&order_id=1001')
        ->assertOk()
        ->assertSee('Naive Laravel AI tool')
        ->assertSee('Manually secured Laravel tool')
        ->assertSee('Verdict BoundTool')
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Run confirmation flow')
        ->assertDontSee('Changed reason rejected')
        ->assertDontSee('The approved executor wrote exactly once.')
        ->assertSee('Order belongs to customer 91.');
});

it('runs argument-bound confirmation independently from the order comparison', function (): void {
    $this->get('/?run_approval=1&order_id=1001')
        ->assertOk()
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Changed reason rejected')
        ->assertSee('The proposed reason changed after the customer approved it.')
        ->assertSee('What happened?')
        ->assertSee('Also reveal the account credit card')
        ->assertSee('approved → unchanged')
        ->assertSee('The approved executor wrote exactly once.')
        ->assertSee('scoped in-memory execution sink')
        ->assertDontSee('Naive Laravel AI tool');
});

it('runs destination-bound context release independently from the other labs', function (): void {
    $this->get('/?run_release=1&order_id=1001')
        ->assertOk()
        ->assertSee('Customer profile before projection')
        ->assertSee('local-machine:ollama-local')
        ->assertSee('remote-network:ollama-local')
        ->assertSee('No raw customer values are stored in these records.')
        ->assertDontSee('Naive Laravel AI tool')
        ->assertDontSee('Changed reason rejected');
});

it('runs the real deterministic evaluation suite independently from the other labs', function (): void {
    $this->get('/?run_evaluation=1&order_id=1001')
        ->assertOk()
        ->assertSee('Security containment')
        ->assertSee('Legitimate task utility')
        ->assertSee('Cross customer order')
        ->assertSee('Owned order')
        ->assertSee('tool_did_not_execute')
        ->assertSee('verdict.evaluation-report.v1')
        ->assertSee('Raw case inputs and outputs are omitted from this report.')
        ->assertDontSee('Naive Laravel AI tool')
        ->assertDontSee('Changed reason rejected')
        ->assertDontSee('Customer profile before projection');
});
