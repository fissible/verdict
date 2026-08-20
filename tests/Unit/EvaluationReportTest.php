<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\EvaluationReport;
use Fissible\Verdict\Tests\Support\Evaluation\PackReferences;
use Fissible\Verdict\Tests\Support\Evaluation\StorefrontReference;

function validEvaluationReportArray(): array
{
    return StorefrontReference::suite()->run()->report()->toArray();
}

it('round-trips every shipped pack report through JSON', function (string $reference): void {
    $report = $reference::suite()->run()->report();

    expect(EvaluationReport::fromJson($report->toJson())->toArray())->toBe($report->toArray());
})->with(PackReferences::ALL);

it('accepts null dispositions on an observation and its tool calls', function (): void {
    $report = validEvaluationReportArray();
    $report['cases'][0]['observation']['disposition'] = null;
    $report['cases'][0]['observation']['tool_calls'][0]['disposition'] = null;

    $parsed = EvaluationReport::fromArray($report)->result()->cases[0]->observation;

    expect($parsed->disposition)->toBeNull()
        ->and($parsed->toolCalls[0]->disposition)->toBeNull();
});

it('accepts a fractional pass rate summary', function (): void {
    $report = validEvaluationReportArray();

    $index = array_find_key(
        $report['cases'],
        static fn (array $case): bool => $case['purpose'] === 'security' && $case['status'] === 'passed',
    );
    expect($index)->not->toBeNull();
    $report['cases'][$index]['status'] = 'failed';

    $security = &$report['scores']['security'];
    $security['passed'] -= 1;
    $security['failed'] += 1;
    $security['pass_rate'] = $security['passed'] / $security['evaluated'];
    unset($security);
    $report['passed'] = false;

    expect(EvaluationReport::fromArray($report)->result()->passed())->toBeFalse();
});

it('rejects JSON that is not an object', function (string $json): void {
    EvaluationReport::fromJson($json);
})->with(['[1,2]', '"report"', '42'])
    ->throws(InvalidArgumentException::class, 'An evaluation report must be a JSON object.');

it('rejects invalid JSON', function (): void {
    EvaluationReport::fromJson('{nope');
})->throws(JsonException::class);

it('rejects malformed report shapes', function (callable $mutate, string $message): void {
    $report = validEvaluationReportArray();

    expect(fn (): EvaluationReport => EvaluationReport::fromArray($mutate($report)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'wrong schema' => [
        function (array $r): array {
            $r['schema'] = 'other.v1';

            return $r;
        },
        'unsupported schema',
    ],
    'missing suite' => [
        function (array $r): array {
            unset($r['suite']);

            return $r;
        },
        'requires a non-empty suite',
    ],
    'blank version' => [
        function (array $r): array {
            $r['version'] = ' ';

            return $r;
        },
        'requires a non-empty version',
    ],
    'non-atom started_at' => [
        function (array $r): array {
            $r['started_at'] = '2026-01-01';

            return $r;
        },
        'must use the DATE_ATOM format',
    ],
    'completion before start' => [
        function (array $r): array {
            [$r['started_at'], $r['completed_at']] = ['2026-01-02T00:00:00+00:00', '2026-01-01T00:00:00+00:00'];

            return $r;
        },
        'completed before it started',
    ],
    'reproduction as list' => [
        function (array $r): array {
            $r['reproduction'] = ['php'];

            return $r;
        },
        'reproduction metadata must be an object',
    ],
    'non-string reproduction version' => [
        function (array $r): array {
            $r['reproduction'] = ['php' => 8];

            return $r;
        },
        'component names and versions must be strings',
    ],
    'cases as object' => [
        function (array $r): array {
            $r['cases'] = ['a' => []];

            return $r;
        },
        'cases must be a list',
    ],
    'case as list' => [
        function (array $r): array {
            $r['cases'][0] = ['x'];

            return $r;
        },
        'case must be an object',
    ],
    'duplicate case id' => [
        function (array $r): array {
            $r['cases'][1] = $r['cases'][0];

            return $r;
        },
        'duplicate case',
    ],
    'invalid purpose' => [
        function (array $r): array {
            $r['cases'][0]['purpose'] = 'chaos';

            return $r;
        },
        'case purpose is invalid',
    ],
    'invalid status' => [
        function (array $r): array {
            $r['cases'][0]['status'] = 'maybe';

            return $r;
        },
        'case status is invalid',
    ],
    'missing case id' => [
        function (array $r): array {
            unset($r['cases'][0]['id']);

            return $r;
        },
        'case requires a non-empty id',
    ],
    'malformed fingerprint' => [
        function (array $r): array {
            $r['cases'][0]['trusted_setup_fingerprint'] = 'zz';

            return $r;
        },
        'requires a SHA-256 trusted_setup_fingerprint',
    ],
    'assertions as object' => [
        function (array $r): array {
            $r['cases'][0]['assertions'] = ['a' => []];

            return $r;
        },
        'requires an assertions list',
    ],
    'assertion as list' => [
        function (array $r): array {
            $r['cases'][0]['assertions'][0] = ['x'];

            return $r;
        },
        'assertion must be an object',
    ],
    'assertion without boolean result' => [
        function (array $r): array {
            $r['cases'][0]['assertions'][0]['passed'] = 'yes';

            return $r;
        },
        'requires a boolean result',
    ],
    'assertion with non-string message' => [
        function (array $r): array {
            $r['cases'][0]['assertions'][0]['message'] = 7;

            return $r;
        },
        'requires a boolean result and optional message',
    ],
    'non-string error class' => [
        function (array $r): array {
            $r['cases'][0]['error_class'] = 500;

            return $r;
        },
        'error class must be a string or null',
    ],
    'non-string blocker' => [
        function (array $r): array {
            $r['cases'][0]['blocked_by'] = 65;

            return $r;
        },
        'blocker must be a string or null',
    ],
    'observation as list' => [
        function (array $r): array {
            $r['cases'][0]['observation'] = ['x'];

            return $r;
        },
        'observation must be an object or null',
    ],
    'observation without executed flag' => [
        function (array $r): array {
            unset($r['cases'][0]['observation']['executed']);

            return $r;
        },
        'observation requires an executed flag',
    ],
    'tool calls as object' => [
        function (array $r): array {
            $r['cases'][0]['observation']['tool_calls'] = ['a' => []];

            return $r;
        },
        'tool calls must be a list',
    ],
    'tool call as list' => [
        function (array $r): array {
            $r['cases'][0]['observation']['tool_calls'][0] = ['x'];

            return $r;
        },
        'tool call must be an object',
    ],
    'tool call without executed flag' => [
        function (array $r): array {
            $r['cases'][0]['observation']['tool_calls'][0]['executed'] = 'yes';

            return $r;
        },
        'tool call requires an executed flag',
    ],
    'invalid tool-call disposition' => [
        function (array $r): array {
            $r['cases'][0]['observation']['tool_calls'][0]['disposition'] = 'shrug';

            return $r;
        },
        'disposition is invalid',
    ],
    'non-string observation disposition' => [
        function (array $r): array {
            $r['cases'][0]['observation']['disposition'] = 3;

            return $r;
        },
        'disposition is invalid',
    ],
    'side effects as object' => [
        function (array $r): array {
            $r['cases'][0]['observation']['side_effect_fingerprints'] = ['a' => 'b'];

            return $r;
        },
        'side effects must be a list',
    ],
    'malformed side-effect fingerprint' => [
        function (array $r): array {
            $r['cases'][0]['observation']['side_effect_fingerprints'] = ['nope'];

            return $r;
        },
        'side-effect fingerprint must be a SHA-256 value',
    ],
    'non-string output fingerprint' => [
        function (array $r): array {
            $r['cases'][0]['observation']['output_fingerprint'] = 9;

            return $r;
        },
        'output fingerprint must be a string or null',
    ],
    'malformed output fingerprint' => [
        function (array $r): array {
            $r['cases'][0]['observation']['output_fingerprint'] = 'short';

            return $r;
        },
        'output fingerprint must be a SHA-256 value',
    ],
    'pass summary mismatch' => [
        function (array $r): array {
            $r['passed'] = ! $r['passed'];

            return $r;
        },
        'pass summary does not match its cases',
    ],
    'scores as list' => [
        function (array $r): array {
            $r['scores'] = [[]];

            return $r;
        },
        'scores must be an object',
    ],
    'missing utility score' => [
        function (array $r): array {
            unset($r['scores']['utility']);

            return $r;
        },
        'missing the utility score',
    ],
    'security score count mismatch' => [
        function (array $r): array {
            $r['scores']['security']['passed'] += 1;

            return $r;
        },
        'security score does not match its cases',
    ],
    'pass rate type mismatch' => [
        function (array $r): array {
            $r['scores']['security']['pass_rate'] = '1';

            return $r;
        },
        'security score does not match its cases',
    ],
    'pass rate where none is computable' => [
        function (array $r): array {
            $r['cases'] = [];
            $r['passed'] = true;
            foreach (['security', 'utility'] as $purpose) {
                $r['scores'][$purpose] = array_fill_keys(
                    ['passed', 'failed', 'errors', 'pending', 'evaluated', 'total'],
                    0,
                );
                $r['scores'][$purpose]['pass_rate'] = 1.0;
            }

            return $r;
        },
        'security score does not match its cases',
    ],
]);
