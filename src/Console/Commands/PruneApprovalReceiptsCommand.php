<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\PrunableApprovalReceiptStore;
use Illuminate\Console\Command;

final class PruneApprovalReceiptsCommand extends Command
{
    protected $signature = 'verdict:prune-approvals {--days= : Retain receipts expired within this many days}';

    protected $description = 'Delete expired Verdict approval receipts that never admitted execution';

    public function handle(ApprovalReceiptStore $store, Clock $clock): int
    {
        if (! $store instanceof PrunableApprovalReceiptStore) {
            $this->components->info('The configured Verdict approval receipt store does not require pruning.');

            return self::SUCCESS;
        }

        $days = $this->option('days') ?? config('verdict.approvals.retention_days');

        if ((! is_int($days) && (! is_string($days) || ! ctype_digit($days))) || (int) $days < 0) {
            $this->components->error('Choose a non-negative approval receipt retention in --days or verdict.approvals.retention_days.');

            return self::FAILURE;
        }

        $days = (int) $days;
        $before = $clock->now()->modify("-{$days} days");
        $pruned = $store->pruneExpired($before);

        $this->components->info("Pruned {$pruned} expired Verdict approval receipt(s).");

        return self::SUCCESS;
    }
}
