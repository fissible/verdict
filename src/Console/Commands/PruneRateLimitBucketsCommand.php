<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\PrunableRateLimitStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Illuminate\Console\Command;

final class PruneRateLimitBucketsCommand extends Command
{
    protected $signature = 'verdict:prune-rate-limits';

    protected $description = 'Delete expired Verdict semantic rate-limit buckets';

    public function handle(RateLimitStore $store, Clock $clock): int
    {
        if (! $store instanceof PrunableRateLimitStore) {
            $this->components->info('The configured Verdict rate-limit store does not require pruning.');

            return self::SUCCESS;
        }

        $pruned = $store->pruneExpired($clock->now());

        $this->components->info("Pruned {$pruned} expired Verdict rate-limit bucket(s).");

        return self::SUCCESS;
    }
}
