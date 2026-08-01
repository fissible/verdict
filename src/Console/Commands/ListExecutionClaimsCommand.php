<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Illuminate\Console\Command;

final class ListExecutionClaimsCommand extends Command
{
    protected $signature = 'verdict:execution-claims';

    protected $description = 'List active or indeterminate Verdict execution claims';

    public function handle(ExecutionClaimManager $claims): int
    {
        $unresolved = $claims->unresolved();

        if ($unresolved === []) {
            $this->components->info('No unresolved Verdict execution claims.');

            return self::SUCCESS;
        }

        $this->table(
            ['Claim ID', 'Capability', 'Policy', 'Status', 'Attempts', 'Updated'],
            array_map(static fn (ExecutionClaim $claim): array => [
                $claim->id,
                $claim->capability,
                $claim->policy,
                $claim->status->value,
                $claim->attemptCount,
                $claim->updatedAt->format(DATE_ATOM),
            ], $unresolved),
        );

        return self::SUCCESS;
    }
}
