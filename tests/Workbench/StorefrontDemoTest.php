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
        ->and($scenario['attempts']['approved']['result'])->toMatchArray([
            'status' => 'cancelled',
            'order_id' => 1002,
        ])
        ->and($scenario['attempts']['replay']['result']['decision'])->toBe('require_confirmation')
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

it('does not execute or reveal results before the comparison is requested', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('The model proposes. Laravel decides.')
        ->assertSee('Run security comparison')
        ->assertDontSee('Naive Laravel AI tool')
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Run confirmation flow')
        ->assertDontSee('Arguments changed after approval')
        ->assertDontSee('The approved executor wrote exactly once.');
});

it('renders the executed workbench-only storefront security lab', function (): void {
    $this->get('/?run_comparison=1&order_id=1001')
        ->assertOk()
        ->assertSee('Naive Laravel AI tool')
        ->assertSee('Manually secured Laravel tool')
        ->assertSee('Verdict BoundTool')
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Run confirmation flow')
        ->assertDontSee('Arguments changed after approval')
        ->assertDontSee('The approved executor wrote exactly once.')
        ->assertSee('Order belongs to customer 91.');
});

it('runs argument-bound confirmation independently from the order comparison', function (): void {
    $this->get('/?run_approval=1&order_id=1001')
        ->assertOk()
        ->assertSee('Argument-bound confirmation')
        ->assertSee('Arguments changed after approval')
        ->assertSee('The approved executor wrote exactly once.')
        ->assertSee('scoped in-memory execution sink')
        ->assertDontSee('Naive Laravel AI tool');
});
