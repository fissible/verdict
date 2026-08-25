<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Illuminate\Console\Command;

/**
 * Verdict's configuration-aware entry point to attest-laravel's verifier.
 *
 * The attest package owns chain traversal, signature verification, anchors, and their exit-code
 * vocabulary. Verdict only resolves the fixed chain it configured and makes the record coverage
 * explicit. A tenant-scoped chain resolver cannot be safely invoked from a process-wide CLI, so
 * such deployments must name the concrete tenant chain with --chain.
 */
final class VerifyEvidenceCommand extends Command
{
    protected $signature = 'verdict:evidence:verify
        {--chain= : Override the configured fixed chain; required with a chain resolver}
        {--from=1 : First sequence number to verify}
        {--to= : Last sequence number to verify}
        {--trusted-key=* : Delegate trusted key(s) to attest:verify}
        {--trusted-key-file=* : Delegate trusted key file(s) to attest:verify}
        {--min-anchor= : Required attest anchor level}
        {--allow-provider-disagreement : Delegate this attest verification option}
        {--allow-untrusted : Delegate this attest verification option}
        {--bitcoin-core-rpc= : Delegate the Bitcoin Core RPC URL to attest:verify}
        {--bitcoin-core-cookie= : Delegate the Bitcoin Core cookie file to attest:verify}
        {--esplora-url= : Delegate the Esplora base URL to attest:verify}
        {--json : Delegate JSON output to attest:verify}';

    protected $description = 'Verify configured Verdict attestation evidence through attest:verify';

    public function handle(): int
    {
        if (! $this->getApplication()?->has('attest:verify')) {
            $this->components->error('Verdict evidence verification requires fissible/attest-laravel and its attest:verify command.');

            return self::FAILURE;
        }

        $chain = $this->chain();

        if ($chain === null) {
            return self::FAILURE;
        }

        if (! $this->usesAttestRecorder($this->getLaravel()->make(EvidenceRecorder::class))) {
            $this->components->error('Verdict evidence verification requires verdict.evidence.recorder to be AttestEvidenceRecorder.');

            return self::FAILURE;
        }

        $chainProvenance = (bool) config('verdict.evidence.attest.chain_provenance');

        if (! $this->option('json')) {
            $this->line("Verdict evidence verification uses attest:verify for chain [{$chain}].");
            $this->line($chainProvenance
                ? 'Verdict coverage: decisions, context releases, and provenance are chained.'
                : 'Verdict coverage: decisions and context releases are chained; provenance is not chained.');
        }

        return $this->call('attest:verify', array_filter([
            '--chain' => $chain,
            '--from' => $this->option('from'),
            '--to' => $this->option('to'),
            '--trusted-key' => $this->option('trusted-key'),
            '--trusted-key-file' => $this->option('trusted-key-file'),
            '--min-anchor' => $this->option('min-anchor'),
            '--allow-provider-disagreement' => $this->option('allow-provider-disagreement') ?: null,
            '--allow-untrusted' => $this->option('allow-untrusted') ?: null,
            '--bitcoin-core-rpc' => $this->option('bitcoin-core-rpc'),
            '--bitcoin-core-cookie' => $this->option('bitcoin-core-cookie'),
            '--esplora-url' => $this->option('esplora-url'),
            '--json' => $this->option('json') ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function chain(): ?string
    {
        $configured = config('verdict.evidence.attest.chain');
        $resolver = config('verdict.evidence.attest.chain_resolver');

        if ($configured !== null && $resolver !== null) {
            $this->components->error('Verdict attest chain topology is invalid: configure exactly one of verdict.evidence.attest.chain or verdict.evidence.attest.chain_resolver.');

            return null;
        }

        $requested = $this->option('chain');

        if (is_string($requested) && trim($requested) !== '') {
            return trim($requested);
        }

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if ($resolver !== null) {
            $this->components->error('A deployment using verdict.evidence.attest.chain_resolver must name its concrete chain with --chain.');

            return null;
        }

        $this->components->error('Verdict has no configured attest chain to verify.');

        return null;
    }

    private function usesAttestRecorder(EvidenceRecorder $recorder): bool
    {
        return $recorder instanceof AttestEvidenceRecorder;
    }
}
