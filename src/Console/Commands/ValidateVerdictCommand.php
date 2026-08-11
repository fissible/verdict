<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Targets\ExecutionTargetStrategy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class ValidateVerdictCommand extends Command
{
    protected $signature = 'verdict:validate';

    protected $description = 'Audit registered Verdict capability wiring without executing actions';

    public function handle(CapabilityRegistry $capabilities, Container $container): int
    {
        $errors = [];
        $warnings = [];
        $information = [];
        $needsApprovals = false;
        $needsRateLimits = false;
        $needsExecutionClaims = false;

        foreach ($capabilities->all() as $capability) {
            if (! $capability->isExecutable()) {
                $warnings[] = "Capability [{$capability->name}] has no executor; this is valid only for GuardedTool-style use.";
            }

            $needsApprovals = $needsApprovals || $capability->confirmationRequired();
            $needsRateLimits = $needsRateLimits || $capability->rateLimitPolicy() !== null;
            $needsExecutionClaims = $needsExecutionClaims || $capability->executionClaimPolicy() !== null;

            if ($capability->executionTargetPolicy()?->strategy === ExecutionTargetStrategy::AcceptStaleSnapshot) {
                $information[] = "Capability [{$capability->name}] deliberately accepts a stale execution-target snapshot.";
            }
        }

        foreach ([
            [
                'needed' => $needsApprovals,
                'contract' => ApprovalReceiptStore::class,
                'label' => 'approval receipt',
            ],
            [
                'needed' => $needsRateLimits,
                'contract' => RateLimitStore::class,
                'label' => 'rate-limit',
            ],
            [
                'needed' => $needsExecutionClaims,
                'contract' => ExecutionClaimStore::class,
                'label' => 'execution-claim',
            ],
        ] as $store) {
            if (! $store['needed']) {
                continue;
            }

            $this->auditStore(
                errors: $errors,
                container: $container,
                contract: $store['contract'],
                label: $store['label'],
            );
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($information as $item) {
            $this->components->info($item);
        }

        if ($errors === [] && $warnings === [] && $information === []) {
            $this->components->info('Verdict wiring audit found no applicable capability configuration.');
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $errors */
    private function auditStore(
        array &$errors,
        Container $container,
        string $contract,
        string $label,
    ): void {
        try {
            $store = $container->make($contract);
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not be resolved.";

            return;
        }

        if (! is_a($store, $contract) || ! $store instanceof DatabaseTableStore) {
            return;
        }

        try {
            if (! $store->hasTable()) {
                $table = $store->table();
                $errors[] = "Configured {$label} store requires missing table [{$table}]. Publish and run Verdict's migrations.";
            }
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not inspect its table.";
        }
    }
}
