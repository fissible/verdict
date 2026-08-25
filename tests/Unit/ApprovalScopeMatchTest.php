<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalScopeMatch;

it('refuses an empty scope and accepts a non-empty one', function (): void {
    expect(fn (): mixed => ApprovalScopeMatch::assertScope([]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => ApprovalScopeMatch::assertScope(['tenant_id' => 1]))->not->toThrow(Exception::class);
});

it('matches only when every scope pair exists with the same typed canonical value', function (): void {
    $context = ['tenant_id' => 1, 'conversation_id' => 'c-1'];

    expect(ApprovalScopeMatch::matches($context, ['tenant_id' => 1]))->toBeTrue()
        ->and(ApprovalScopeMatch::matches($context, ['tenant_id' => 1, 'conversation_id' => 'c-1']))->toBeTrue()
        ->and(ApprovalScopeMatch::matches($context, ['tenant_id' => '1']))->toBeFalse()
        ->and(ApprovalScopeMatch::matches(['tenant_id' => '1'], ['tenant_id' => 1]))->toBeFalse()
        ->and(ApprovalScopeMatch::matches($context, ['missing' => 1]))->toBeFalse()
        ->and(ApprovalScopeMatch::matches($context, ['tenant_id' => 1, 'missing' => 'x']))->toBeFalse();
});

it('never matches a null or empty context', function (): void {
    expect(ApprovalScopeMatch::matches(null, ['tenant_id' => 1]))->toBeFalse()
        ->and(ApprovalScopeMatch::matches([], ['tenant_id' => 1]))->toBeFalse();
});
