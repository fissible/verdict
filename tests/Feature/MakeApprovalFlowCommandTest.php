<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalManager;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

function approvalFlowGeneratedPaths(): array
{
    return [
        app_path('Http/Controllers/VerdictApprovalDecisionController.php'),
        app_path('Http/Requests/DecideVerdictApprovalRequest.php'),
        app_path('Support/VerdictApprovalAuthorizer.php'),
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
    $authorizer = file_get_contents(app_path('Support/VerdictApprovalAuthorizer.php'));
    $routes = file_get_contents(base_path('routes/verdict-approval-flow.php'));
    $guide = file_get_contents(base_path('docs/verdict-approval-flow.md'));

    expect($controller)->toContain('ApprovalManager', 'approve(', 'reject(', 'receiptId:', 'toolCallId:', 'approvedBy:', 'rejectedBy:', 'challengeForToolCall($toolCallId)', 'verdict.approvals.authorizer', 'fail-closed', 'not_found, mismatch, expired, invalid_state, and unauthorized', 'TODO: Resume the application-owned agent/conversation')
        ->and($request)->toContain('per-receipt authorization', 'VerdictApprovalAuthorizer')
        ->and($authorizer)->toContain('ApprovalDecisionAuthorizer', 'approvalContext', 'conversation_id', 'return false', 'verdict.approvals.authorizer')
        ->and($routes)->toContain('deliberately not included', "//     Route::post('/verdict/approvals/approve'")
        ->not->toMatch('/^\s*Route::post\(/m')
        ->and($guide)->toContain('opaque application identifier', 'did not register the route file', 'challengeForToolCall($toolCallId)', 'already-decided or already-consumed receipt returns `invalid_state`', 'https://github.com/fissible/verdict/blob/main/docs/adoption-guide.md', '#103', 'raw prompts or tool arguments into Verdict receipts', 'verdict.approvals.authorizer', '`unauthorized`')
        ->and($guide)->not->toContain('store raw prompts');
});

it('keeps generated PHP syntactically valid and coupled to the approval decision API', function (): void {
    $this->artisan('verdict:make-approval-flow')->assertSuccessful();

    foreach (array_filter(approvalFlowGeneratedPaths(), fn (string $path): bool => str_ends_with($path, '.php')) as $path) {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->mustRun();
    }

    expect((new ReflectionMethod(ApprovalManager::class, 'approve'))->getParameters())
        ->sequence(
            fn ($parameter) => $parameter->getName()->toBe('receiptId'),
            fn ($parameter) => $parameter->getName()->toBe('toolCallId'),
            fn ($parameter) => $parameter->getName()->toBe('approvedBy'),
        )
        ->and((new ReflectionMethod(ApprovalManager::class, 'reject'))->getParameters())
        ->sequence(
            fn ($parameter) => $parameter->getName()->toBe('receiptId'),
            fn ($parameter) => $parameter->getName()->toBe('toolCallId'),
            fn ($parameter) => $parameter->getName()->toBe('rejectedBy'),
        );
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
