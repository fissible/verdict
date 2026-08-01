<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final readonly class StorefrontDemoController
{
    public function __construct(private StorefrontScenarioRunner $runner) {}

    public function __invoke(Request $request): View
    {
        $orderId = $request->integer('order_id', 1001);

        if (! in_array($orderId, [1001, 1002], true)) {
            $orderId = 1001;
        }

        $hasComparisonRun = $request->boolean('run_comparison') || $request->boolean('run');
        $hasApprovalRun = $request->boolean('run_approval');
        $hasReleaseRun = $request->boolean('run_release');
        $hasEvaluationRun = $request->boolean('run_evaluation');

        return view('verdict-workbench::storefront', [
            'scenario' => $this->runner->preview($orderId),
            'comparison' => $hasComparisonRun ? $this->runner->comparison($orderId) : null,
            'approval' => $hasApprovalRun ? $this->runner->approvalReplay() : null,
            'release' => $hasReleaseRun ? $this->runner->contextRelease() : null,
            'evaluation' => $hasEvaluationRun ? $this->runner->securityEvaluation() : null,
            'hasComparisonRun' => $hasComparisonRun,
            'hasApprovalRun' => $hasApprovalRun,
            'hasReleaseRun' => $hasReleaseRun,
            'hasEvaluationRun' => $hasEvaluationRun,
        ]);
    }
}
