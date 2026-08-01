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

        $hasRun = $request->boolean('run');

        return view('verdict-workbench::storefront', [
            'scenario' => $this->runner->preview($orderId),
            'comparison' => $hasRun ? $this->runner->comparison($orderId) : null,
            'approval' => $hasRun ? $this->runner->approvalReplay() : null,
            'hasRun' => $hasRun,
        ]);
    }
}
