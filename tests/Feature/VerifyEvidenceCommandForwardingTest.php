<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;

/**
 * #321.1 — `verdict:evidence:verify` builds the delegated attest:verify option list with a filter
 * (`$v !== null && $v !== ''`) that keeps `--from` (defaults to '1', so always present) and an empty
 * `--trusted-key`/`--trusted-key-file` (`[]` survives the filter). Those override attest:verify's own
 * defaults instead of deferring to them. Only operator-set options should be forwarded.
 */
const FAKE_ATTEST_VERIFY = 'attest:verify {--chain=} {--from=} {--to=} {--trusted-key=*} {--trusted-key-file=*} {--min-anchor=} {--allow-provider-disagreement} {--allow-untrusted} {--bitcoin-core-rpc=} {--bitcoin-core-cookie=} {--esplora-url=} {--json}';

beforeEach(function (): void {
    config()->set('verdict.evidence.attest.chain', 'verdict');

    // A real AttestEvidenceRecorder built from the test fixture — enough for the command's
    // usesAttestRecorder() check to pass, without standing up attest signing infrastructure.
    app()->instance(EvidenceRecorder::class, new AttestEvidenceRecorder(
        attest: AttestFixture::registry(),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
    ));
});

it('forwards only operator-set options to attest:verify, not defaults or empty arrays (#321)', function (): void {
    $forwarded = null;

    // A fake attest:verify (overrides the real one) that records which options were actually passed.
    Artisan::command(FAKE_ATTEST_VERIFY, function () use (&$forwarded): int {
        $forwarded = [
            'from' => $this->input->hasParameterOption('--from'),
            'trusted-key' => $this->input->hasParameterOption('--trusted-key'),
            'trusted-key-file' => $this->input->hasParameterOption('--trusted-key-file'),
        ];

        return 0;
    });

    $this->artisan('verdict:evidence:verify')->assertExitCode(0);

    expect($forwarded)->not->toBeNull('The delegated attest:verify was never reached.')
        ->and($forwarded['from'])->toBeFalse('--from=1 (the default) must not be forwarded')
        ->and($forwarded['trusted-key'])->toBeFalse('an empty --trusted-key must not be forwarded')
        ->and($forwarded['trusted-key-file'])->toBeFalse('an empty --trusted-key-file must not be forwarded');
});

it('forwards an operator-set --from and --trusted-key to attest:verify (#321)', function (): void {
    $forwarded = null;

    Artisan::command(FAKE_ATTEST_VERIFY, function () use (&$forwarded): int {
        $forwarded = [
            'from' => $this->option('from'),
            'trusted-key' => $this->option('trusted-key'),
        ];

        return 0;
    });

    $this->artisan('verdict:evidence:verify', ['--from' => '5', '--trusted-key' => ['verdict-test=abc']])->assertExitCode(0);

    expect($forwarded)->not->toBeNull()
        ->and($forwarded['from'])->toBe('5')
        ->and($forwarded['trusted-key'])->toBe(['verdict-test=abc']);
});
