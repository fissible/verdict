<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

function approvalFlowGeneratedPaths(): array
{
    return [
        app_path('Http/Controllers/VerdictApprovalDecisionController.php'),
        app_path('Http/Requests/DecideVerdictApprovalRequest.php'),
        app_path('Jobs/NotifyVerdictApprovalDecision.php'),
        base_path('routes/verdict-approval-flow.php'),
        base_path('docs/verdict-approval-flow.md'),
    ];
}

function removeApprovalFlowGeneratedFiles(): void
{
    foreach (approvalFlowGeneratedPaths() as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

beforeEach(function (): void {
    removeApprovalFlowGeneratedFiles();
});

afterEach(function (): void {
    removeApprovalFlowGeneratedFiles();
});

it('publishes approval-flow skeletons only when explicitly invoked', function (): void {
    expect(approvalFlowGeneratedPaths())->each->not->toBeFile();

    $routeCount = count(Route::getRoutes());

    $this->artisan('verdict:make-approval-flow')
        ->expectsOutputToContain('No routes, middleware, jobs, notifications, policies, or views were registered')
        ->assertSuccessful();

    expect(approvalFlowGeneratedPaths())->each->toBeFile()
        ->and(count(Route::getRoutes()))->toBe($routeCount);
});

it('publishes application-owned decision outcomes and disabled route scaffolding', function (): void {
    $this->artisan('verdict:make-approval-flow')->assertSuccessful();

    $controller = file_get_contents(app_path('Http/Controllers/VerdictApprovalDecisionController.php'));
    $request = file_get_contents(app_path('Http/Requests/DecideVerdictApprovalRequest.php'));
    $routes = file_get_contents(base_path('routes/verdict-approval-flow.php'));
    $guide = file_get_contents(base_path('docs/verdict-approval-flow.md'));

    expect($controller)->toContain('ApprovalManager', 'approve(', 'reject(', 'not_found, mismatch, expired, and invalid_state', 'TODO: Resume the application-owned agent/conversation')
        ->and($request)->toContain('TODO: Check the authenticated reviewer')
        ->and($routes)->toContain('deliberately not included', "//     Route::post('/verdict/approvals/approve'")
        ->not->toMatch('/^\s*Route::post\(/m')
        ->and($guide)->toContain('opaque application identifier', 'did not register the route file', 'adoption guide', '#103', 'raw prompts or tool arguments into Verdict receipts')
        ->and($guide)->not->toContain('store raw prompts');
});

it('does not replace existing application files without force', function (): void {
    $path = app_path('Http/Controllers/VerdictApprovalDecisionController.php');
    $contents = '<?php // application owned';
    file_put_contents($path, $contents);

    $this->artisan('verdict:make-approval-flow')
        ->expectsOutputToContain('Skipped existing')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe($contents);
});
