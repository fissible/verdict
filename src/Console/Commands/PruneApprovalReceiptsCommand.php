<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Fissible\Verdict\Contracts\PrunesConsumedApprovalPayload;
use Illuminate\Console\Command;
use RuntimeException;

final class PruneApprovalReceiptsCommand extends Command
{
    protected $signature = 'verdict:prune-approvals {--days= : Retain receipts expired within this many days}
                            {--consumed-days= : Retain consumed receipt payloads (guarded) within this many days}';

    protected $description = 'Delete expired Verdict approval receipts that never admitted execution';

    public function handle(ApprovalReceiptStore $store, Clock $clock): int
    {
        $expiredDays = $this->option('days') ?? config('verdict.approvals.retention_days');
        $consumedDays = $this->option('consumed-days') ?? config('verdict.approvals.consumed_retention_days');

        if ($expiredDays === null && $consumedDays === null) {
            $this->components->error('Choose retention in --days or verdict.approvals.retention_days, or --consumed-days or verdict.approvals.consumed_retention_days.');

            return self::FAILURE;
        }

        if ($expiredDays !== null && ((! is_int($expiredDays) && (! is_string($expiredDays) || ! ctype_digit($expiredDays))) || (int) $expiredDays < 0)) {
            $this->components->error('Choose a non-negative approval receipt retention in --days or verdict.approvals.retention_days.');

            return self::FAILURE;
        }

        if ($consumedDays !== null && ((! is_int($consumedDays) && (! is_string($consumedDays) || ! ctype_digit($consumedDays))) || (int) $consumedDays < 0)) {
            $this->components->error('Choose a non-negative consumed payload retention in --consumed-days or verdict.approvals.consumed_retention_days.');

            return self::FAILURE;
        }

        if ($consumedDays !== null) {
            if (! $store instanceof PrunesConsumedApprovalPayload) {
                $this->components->info('The configured Verdict approval receipt store cannot prune consumed payloads.');
            } else {
                $days = (int) $consumedDays;
                $before = $clock->now()->modify("-{$days} days");

                try {
                    $pruned = $store->pruneConsumedPayload($before);
                } catch (RuntimeException) {
                    $this->components->error('Cannot prune consumed payloads without preserving the replay guard.');

                    return self::FAILURE;
                }

                $this->components->info("Pruned {$pruned} consumed Verdict approval payload(s).");
            }
        }

        if ($expiredDays !== null) {
            if (! $store instanceof PrunableApprovalReceiptStore) {
                $this->components->info('The configured Verdict approval receipt store does not require pruning.');
            } else {
                $days = (int) $expiredDays;
                $before = $clock->now()->modify("-{$days} days");
                $pruned = $store->pruneExpired($before);

                $this->components->info("Pruned {$pruned} expired Verdict approval receipt(s).");
            }
        }

        return self::SUCCESS;
    }
}
