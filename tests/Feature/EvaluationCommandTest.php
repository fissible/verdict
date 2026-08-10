<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\EvaluationBaseline;
use Fissible\Verdict\Evaluation\EvaluationReportFile;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'verdict-evaluation-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    config()->set('verdict-tests.evaluation-directory', $directory);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(evaluationCommandDirectory());
});

function evaluationCommandDirectory(): string
{
    $directory = config('verdict-tests.evaluation-directory');

    assert(is_string($directory));

    return $directory;
}

function evaluationCommandPath(string $name): string
{
    return evaluationCommandDirectory().DIRECTORY_SEPARATOR.$name;
}

/** @return array<string, mixed> */
function evaluationCommandCase(
    string $id,
    string $status,
    string $purpose = 'security',
): array {
    return [
        'id' => $id,
        'version' => '1',
        'purpose' => $purpose,
        'status' => $status,
        'trusted_setup_fingerprint' => str_repeat('a', 64),
        'untrusted_input_fingerprint' => str_repeat('b', 64),
        'error_class' => $status === 'error' ? RuntimeException::class : null,
        'blocked_by' => $status === 'pending' ? '#30 derivation edges' : null,
        'assertions' => $status === 'pending' ? [] : [[
            'assertion' => 'expected behavior',
            'passed' => $status === 'passed',
            'message' => null,
        ]],
        'observation' => null,
    ];
}

/**
 * @param  list<array<string, mixed>>  $cases
 * @param  array<string, mixed>  $extra
 */
function evaluationCommandReport(array $cases, array $extra = []): string
{
    $scores = [];

    foreach (['security', 'utility'] as $purpose) {
        $purposeCases = array_values(array_filter(
            $cases,
            static fn (array $case): bool => $case['purpose'] === $purpose,
        ));
        $passed = count(array_filter(
            $purposeCases,
            static fn (array $case): bool => $case['status'] === 'passed',
        ));
        $failed = count(array_filter(
            $purposeCases,
            static fn (array $case): bool => $case['status'] === 'failed',
        ));
        $errors = count(array_filter(
            $purposeCases,
            static fn (array $case): bool => $case['status'] === 'error',
        ));
        $pending = count(array_filter(
            $purposeCases,
            static fn (array $case): bool => $case['status'] === 'pending',
        ));
        $evaluated = $passed + $failed;

        $scores[$purpose] = [
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
            'pending' => $pending,
            'evaluated' => $evaluated,
            'total' => $evaluated + $errors + $pending,
            'pass_rate' => $evaluated === 0 ? null : $passed / $evaluated,
        ];
    }

    return json_encode([
        'schema' => 'verdict.evaluation-report.v1',
        'suite' => 'command-test-suite',
        'version' => '1',
        'passed' => array_all($cases, static fn (array $case): bool => $case['status'] === 'passed'),
        'started_at' => '2026-08-01T12:00:00+00:00',
        'completed_at' => '2026-08-01T12:00:01+00:00',
        'reproduction' => ['php' => PHP_VERSION],
        'scores' => $scores,
        'cases' => $cases,
        ...$extra,
    ], JSON_THROW_ON_ERROR);
}

function writeEvaluationCommandReport(string $name, string $report): string
{
    $path = evaluationCommandPath($name);
    file_put_contents($path, $report);

    return $path;
}

it('writes a validated baseline and only replaces it when forced', function (): void {
    $firstReport = writeEvaluationCommandReport('first.json', evaluationCommandReport(
        [evaluationCommandCase('cross-customer-cancel', 'passed')],
        ['raw_model_input' => 'never persist this secret'],
    ));
    $secondReport = writeEvaluationCommandReport('second.json', evaluationCommandReport([
        evaluationCommandCase('cross-customer-cancel', 'failed'),
    ]));
    $baseline = evaluationCommandPath('baseline.json');

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $firstReport,
        'baseline' => $baseline,
    ])->assertSuccessful();

    $firstContents = file_get_contents($baseline);

    expect($firstContents)->toBeString();

    if (! is_string($firstContents)) {
        return;
    }

    expect($firstContents)->not->toContain('never persist this secret')
        ->and(EvaluationBaseline::fromJson($firstContents)->suite)->toBe('command-test-suite');

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $secondReport,
        'baseline' => $baseline,
    ])->expectsOutputToContain('already exists')->assertExitCode(2);

    expect(file_get_contents($baseline))->toBe($firstContents);

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $secondReport,
        'baseline' => $baseline,
        '--force' => true,
    ])->assertSuccessful();

    expect(file_get_contents($baseline))->not->toBe($firstContents)
        ->and(glob($baseline.'*'))->toBe([$baseline]);
});

it('rejects missing, malformed, wrong-schema, and unwritable baseline inputs', function (): void {
    $malformed = writeEvaluationCommandReport('malformed.json', '{');
    $wrongSchema = writeEvaluationCommandReport('wrong-schema.json', evaluationCommandReport(
        [evaluationCommandCase('cross-customer-cancel', 'passed')],
        ['schema' => 'verdict.evaluation-report.v0'],
    ));
    $valid = writeEvaluationCommandReport('valid.json', evaluationCommandReport([
        evaluationCommandCase('cross-customer-cancel', 'passed'),
    ]));
    $directoryTarget = evaluationCommandPath('directory-target');
    mkdir($directoryTarget);

    $this->artisan('verdict:evaluation-baseline', [
        'report' => evaluationCommandPath('missing.json'),
        'baseline' => evaluationCommandPath('baseline.json'),
    ])->assertExitCode(2);

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $malformed,
        'baseline' => evaluationCommandPath('baseline.json'),
    ])->assertExitCode(2);

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $wrongSchema,
        'baseline' => evaluationCommandPath('baseline.json'),
    ])->assertExitCode(2);

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $valid,
        'baseline' => $directoryTarget,
    ])->expectsOutputToContain('points to a directory')->assertExitCode(2);

    $this->app->instance(EvaluationReportFile::class, new EvaluationReportFile(
        new class extends Filesystem
        {
            public function isWritable($path): bool
            {
                return false;
            }
        },
    ));

    $this->artisan('verdict:evaluation-baseline', [
        'report' => $valid,
        'baseline' => evaluationCommandPath('unwritable.json'),
    ])->expectsOutputToContain('missing or not writable')->assertExitCode(2);
});

it('groups every comparison kind and returns failure for blocking changes', function (): void {
    $baseline = writeEvaluationCommandReport('baseline.json', evaluationCommandReport([
        evaluationCommandCase('regression', 'passed'),
        evaluationCommandCase('new-failure', 'error'),
        evaluationCommandCase('harness-error', 'passed'),
        evaluationCommandCase('removed-coverage', 'passed'),
        evaluationCommandCase('improvement', 'failed'),
        evaluationCommandCase('recovery', 'error'),
    ]));
    $current = writeEvaluationCommandReport('current.json', evaluationCommandReport([
        evaluationCommandCase('regression', 'failed'),
        evaluationCommandCase('new-failure', 'failed'),
        evaluationCommandCase('harness-error', 'error'),
        evaluationCommandCase('improvement', 'passed'),
        evaluationCommandCase('recovery', 'passed'),
        evaluationCommandCase('added-coverage', 'passed'),
    ]));

    $command = $this->artisan('verdict:evaluation-compare', [
        'current' => $current,
        'baseline' => $baseline,
    ]);

    foreach ([
        'Behavioral regressions',
        'New behavioral failures',
        'Harness errors',
        'Removed coverage',
        'Improvements',
        'Recoveries',
        'Added coverage',
    ] as $heading) {
        $command->expectsOutputToContain($heading);
    }

    $command->assertExitCode(1);
});

it('returns success when the comparison has only non-blocking changes', function (): void {
    $baseline = writeEvaluationCommandReport('baseline.json', evaluationCommandReport([
        evaluationCommandCase('improvement', 'failed'),
        evaluationCommandCase('recovery', 'error'),
    ]));
    $current = writeEvaluationCommandReport('current.json', evaluationCommandReport([
        evaluationCommandCase('improvement', 'passed'),
        evaluationCommandCase('recovery', 'passed'),
        evaluationCommandCase('added-coverage', 'passed'),
    ]));

    $this->artisan('verdict:evaluation-compare', [
        'current' => $current,
        'baseline' => $baseline,
    ])->assertSuccessful();
});

it('fails when newly added coverage is pending', function (): void {
    $baseline = writeEvaluationCommandReport('baseline.json', evaluationCommandReport([
        evaluationCommandCase('existing-coverage', 'passed'),
    ]));
    $current = writeEvaluationCommandReport('current.json', evaluationCommandReport([
        evaluationCommandCase('existing-coverage', 'passed'),
        evaluationCommandCase('blocked-coverage', 'pending'),
    ]));

    $this->artisan('verdict:evaluation-compare', [
        'current' => $current,
        'baseline' => $baseline,
    ])->expectsOutputToContain('Suspended coverage')->assertExitCode(1);
});

it('returns invalid for missing or malformed comparison inputs and formats', function (): void {
    $valid = writeEvaluationCommandReport('valid.json', evaluationCommandReport([
        evaluationCommandCase('cross-customer-cancel', 'passed'),
    ]));
    $malformed = writeEvaluationCommandReport('malformed.json', '{');

    $this->artisan('verdict:evaluation-compare', [
        'current' => evaluationCommandPath('missing.json'),
        'baseline' => $valid,
    ])->assertExitCode(2);

    $this->artisan('verdict:evaluation-compare', [
        'current' => $malformed,
        'baseline' => $valid,
    ])->assertExitCode(2);

    $this->artisan('verdict:evaluation-compare', [
        'current' => $valid,
        'baseline' => $valid,
        '--format' => 'xml',
    ])->assertExitCode(2);
});

it('emits escaped GitHub annotations without leaking unknown report fields', function (): void {
    $caseId = "line%\r\ncase";
    $baseline = writeEvaluationCommandReport('baseline.json', evaluationCommandReport([
        evaluationCommandCase($caseId, 'passed'),
    ]));
    $current = writeEvaluationCommandReport('current.json', evaluationCommandReport(
        [evaluationCommandCase($caseId, 'failed')],
        ['raw_model_output' => 'SUPER-SECRET-MODEL-BYTES'],
    ));

    $exitCode = Artisan::call('verdict:evaluation-compare', [
        'current' => $current,
        'baseline' => $baseline,
        '--format' => 'github',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('::error title=Verdict Behavioral regressions::')
        ->and($output)->toContain('case_id=line%25%0D%0Acase')
        ->and($output)->toContain('category=behavioral_regression')
        ->and($output)->not->toContain('SUPER-SECRET-MODEL-BYTES');
});
