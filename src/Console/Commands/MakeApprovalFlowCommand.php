<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class MakeApprovalFlowCommand extends Command
{
    protected $signature = 'verdict:make-approval-flow {--force : Replace existing generated files}';

    protected $description = 'Publish route-free, application-owned approval-flow skeletons';

    public function handle(Filesystem $files): int
    {
        $templates = [
            __DIR__.'/../../../stubs/approval-flow/ApprovalDecisionController.php.stub' => app_path('Http/Controllers/VerdictApprovalDecisionController.php'),
            __DIR__.'/../../../stubs/approval-flow/DecideVerdictApprovalRequest.php.stub' => app_path('Http/Requests/DecideVerdictApprovalRequest.php'),
            __DIR__.'/../../../stubs/approval-flow/NotifyVerdictApprovalDecision.php.stub' => app_path('Jobs/NotifyVerdictApprovalDecision.php'),
            __DIR__.'/../../../stubs/approval-flow/verdict-approval-flow.php.stub' => base_path('routes/verdict-approval-flow.php'),
            __DIR__.'/../../../stubs/approval-flow/README.md.stub' => base_path('docs/verdict-approval-flow.md'),
        ];

        $written = 0;

        foreach ($templates as $source => $destination) {
            if ($files->exists($destination) && ! $this->option('force')) {
                $this->components->warn("Skipped existing [{$destination}]. Use --force to replace it.");

                continue;
            }

            $files->ensureDirectoryExists(dirname($destination));
            $files->copy($source, $destination);
            $this->components->info("Published [{$destination}].");
            $written++;
        }

        if ($written === 0) {
            return self::SUCCESS;
        }

        $this->components->warn('No routes, middleware, jobs, notifications, policies, or views were registered. Review the generated README before including the route snippet.');

        return self::SUCCESS;
    }
}
