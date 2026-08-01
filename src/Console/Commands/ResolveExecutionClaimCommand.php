<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Illuminate\Console\Command;

final class ResolveExecutionClaimCommand extends Command
{
    protected $signature = 'verdict:resolve-execution-claim
        {claim : The opaque execution claim ID}
        {resolution : completed or retryable}
        {--by= : Application-defined operator identity}
        {--reason= : Reconciliation evidence}
        {--force : Permit reconciliation of a claim still marked active}';

    protected $description = 'Resolve an active or indeterminate Verdict execution claim';

    public function handle(ExecutionClaimManager $claims): int
    {
        $claimId = $this->stringArgument('claim');
        $resolution = ExecutionClaimResolution::tryFrom($this->stringArgument('resolution'));
        $resolvedBy = $this->stringOption('by');
        $reason = $this->stringOption('reason');

        if ($resolution === null) {
            $this->components->error('Resolution must be [completed] or [retryable].');

            return self::INVALID;
        }

        if ($resolvedBy === '' || $reason === '') {
            $this->components->error('The --by and --reason options are required.');

            return self::INVALID;
        }

        $claim = $claims->find($claimId);

        if ($claim === null) {
            $this->components->error('Execution claim was not found.');

            return self::FAILURE;
        }

        if ($claim->status === ExecutionClaimStatus::Claimed && ! $this->option('force')) {
            $this->components->error('The claim is still active. Investigate it, then repeat with --force.');

            return self::FAILURE;
        }

        $transition = $claims->resolve($claimId, $resolution, $resolvedBy, $reason);

        if (! in_array($transition->outcome, [ExecutionClaimOutcome::Completed, ExecutionClaimOutcome::Released], true)) {
            $this->components->error("Execution claim could not be resolved from state [{$claim->status->value}].");

            return self::FAILURE;
        }

        $this->components->info(
            $transition->outcome === ExecutionClaimOutcome::Released
                ? 'Execution claim released for one explicit retry.'
                : 'Execution claim marked completed.',
        );

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? trim($value) : '';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
