<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\Targets\ExecutionTargetStrategy;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class ValidateVerdictCommand extends Command
{
    protected $signature = 'verdict:validate';

    protected $description = 'Audit registered Verdict capability wiring without executing actions';

    public function handle(CapabilityRegistry $capabilities, DatabaseManager $database): int
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
                'configKey' => 'verdict.approvals',
                'contract' => ApprovalReceiptStore::class,
                'databaseStore' => DatabaseApprovalReceiptStore::class,
                'label' => 'approval receipt',
                'defaultTable' => 'verdict_approval_receipts',
            ],
            [
                'needed' => $needsRateLimits,
                'configKey' => 'verdict.rate_limits',
                'contract' => RateLimitStore::class,
                'databaseStore' => DatabaseRateLimitStore::class,
                'label' => 'rate-limit',
                'defaultTable' => 'verdict_rate_limit_buckets',
            ],
            [
                'needed' => $needsExecutionClaims,
                'configKey' => 'verdict.execution_claims',
                'contract' => ExecutionClaimStore::class,
                'databaseStore' => DatabaseExecutionClaimStore::class,
                'label' => 'execution-claim',
                'defaultTable' => 'verdict_execution_claims',
            ],
        ] as $store) {
            if (! $store['needed']) {
                continue;
            }

            $this->auditStore(
                errors: $errors,
                database: $database,
                configKey: $store['configKey'],
                contract: $store['contract'],
                databaseStore: $store['databaseStore'],
                label: $store['label'],
                defaultTable: $store['defaultTable'],
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
        DatabaseManager $database,
        string $configKey,
        string $contract,
        string $databaseStore,
        string $label,
        string $defaultTable,
    ): void {
        $store = config("{$configKey}.store", $databaseStore);

        if (! is_string($store) || ! class_exists($store) || ! is_a($store, $contract, true)) {
            $errors[] = "Configured {$label} store must name a {$contract} implementation.";

            return;
        }

        if ($store !== $databaseStore) {
            return;
        }

        $connection = config("{$configKey}.connection");
        $table = config("{$configKey}.table", $defaultTable);

        if ($connection !== null && ! is_string($connection)) {
            $errors[] = "Configured {$label} connection must be a string or null.";

            return;
        }

        if (! is_string($table) || trim($table) === '') {
            $errors[] = "Configured {$label} table must be a non-empty string.";

            return;
        }

        try {
            if (! $database->connection($connection)->getSchemaBuilder()->hasTable($table)) {
                $errors[] = "Configured {$label} store requires missing table [{$table}]. Publish and run Verdict's migrations.";
            }
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not inspect table [{$table}].";
        }
    }
}
