<?php

declare(strict_types=1);

use App\Capabilities\Orders\RefundCapability;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->capabilityOutputRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'verdict-make-capability-'.Str::uuid();
});

afterEach(function (): void {
    app(Filesystem::class)->deleteDirectory($this->capabilityOutputRoot);
});

/** @return array<string, mixed> */
function makeCapabilityArguments(object $test, array $overrides = []): array
{
    return [
        'capability' => 'orders.refund',
        '--model' => 'Order',
        '--ability' => 'refund',
        '--target-argument' => 'order_id',
        '--path' => $test->capabilityOutputRoot,
        '--no-interaction' => true,
        ...$overrides,
    ];
}

it('generates fail-closed selected-control wiring noninteractively', function (): void {
    $this->artisan('verdict:make-capability', makeCapabilityArguments($this, [
        '--confirmation' => true,
        '--claim' => true,
        '--rate-limit' => true,
    ]))
        ->expectsOutputToContain('Add a fail-closed policy method')
        ->expectsOutputToContain('Register this capability in your application provider')
        ->expectsOutputToContain('php artisan verdict:validate')
        ->assertExitCode(0);

    $capability = $this->capabilityOutputRoot.'/app/Capabilities/Orders/RefundCapability.php';
    $test = $this->capabilityOutputRoot.'/tests/Feature/Capabilities/Orders/RefundCapabilityTest.php';

    expect($capability)->toBeFile()
        ->and($test)->toBeFile()
        ->and(file_get_contents($capability))->toContain(
            'ExecutionTargetPolicy::refresh(',
            'TODO: resolve order_id through tenant- and ownership-scoped application data.',
            'TODO: bind approval to canonical application-owned facts.',
            'TODO: bind duplicate admission to canonical application-owned identity.',
            'TODO: choose application-owned rate-limit scope, limit, window, and binding.',
            "throw new LogicException('TODO: implement the application side effect",
        )->not->toContain('findOrFail', 'limit:', 'windowSeconds:')
        ->and(file_get_contents($test))->toContain(
            'CapabilitySecurityTestKit::for(',
            'assertPolicyDenial(',
            'assertRefreshedTargetSubstitution(',
            'approval binding invalidation',
            'assertApprovalBindingInvalidation(',
            'execution-claim duplicate admission',
            'assertExecutionClaimDuplicateAdmission(',
            'rate-limit enforcement',
            'assertRateLimitEnforcement(',
        );

    require_once $capability;

    expect(fn () => RefundCapability::make())
        ->toThrow(LogicException::class, 'rate-limit scope, limit, window, and binding');
});

it('refuses collisions unless force is explicit', function (): void {
    $arguments = makeCapabilityArguments($this);
    $this->artisan('verdict:make-capability', $arguments)->assertExitCode(0);

    $capability = $this->capabilityOutputRoot.'/app/Capabilities/Orders/RefundCapability.php';
    file_put_contents($capability, '<?php // preserved until force');

    $this->artisan('verdict:make-capability', $arguments)
        ->expectsOutputToContain('Refusing to overwrite existing generated files')
        ->assertExitCode(1);

    expect(file_get_contents($capability))->toBe('<?php // preserved until force');

    $this->artisan('verdict:make-capability', makeCapabilityArguments($this, ['--force' => true]))
        ->assertExitCode(0);

    expect(file_get_contents($capability))->toContain('final class RefundCapability');
});

it('requires every noninteractive structural decision', function (): void {
    $this->artisan('verdict:make-capability', [
        'capability' => 'orders.refund',
        '--ability' => 'refund',
        '--target-argument' => 'order_id',
        '--path' => $this->capabilityOutputRoot,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('The [--model] value is required when not running interactively.')
        ->assertExitCode(1);
});

it('asks the same structural questions that the noninteractive flags provide', function (): void {
    $this->artisan('verdict:make-capability', ['--path' => $this->capabilityOutputRoot])
        ->expectsQuestion('Capability name', 'orders.refund')
        ->expectsQuestion('Model class', 'Order')
        ->expectsQuestion('Authorization ability', 'refund')
        ->expectsQuestion('Target argument', 'order_id')
        ->expectsQuestion('Require a human approval binding?', true)
        ->expectsQuestion('Require at-most-once execution admission?', true)
        ->expectsQuestion('Require a semantic rate limit?', true)
        ->assertExitCode(0);

    expect($this->capabilityOutputRoot.'/app/Capabilities/Orders/RefundCapability.php')->toBeFile();
});
