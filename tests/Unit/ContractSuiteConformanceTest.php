<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/**
 * What makes `tests/Contract/` a CONTRACT rather than more coverage (#340, ADR 0033 §6).
 *
 * The suite's job is to say, when laravel/ai changes, which Verdict guarantee just lost its
 * footing. That works only if each test names the consumer-side consequence and is honest about
 * whether it exercised upstream's real runtime or a hand-built input — a "contract test" built on a
 * mock of the thing it means to pin proves only Verdict's own logic, which is exactly what
 * `docs/laravel-ai-compatibility.md` records about two of our existing tests.
 *
 * These are meta-tests: they constrain the shape and honesty of that suite, not its assertions.
 * They cannot make a test meaningful — only the mutation verification #340 requires can do that —
 * but they close the shapes in which a meaningless suite could look finished.
 */

/**
 * Every behaviour the contract suite must cover.
 *
 * ADR 0033 §6 listed ten. Three more were found while specifying this issue, all documented
 * dependencies the ADR's table missed, and the ADR should gain an Update recording them:
 *
 *   step-text-gateway — `docs/laravel-ai-compatibility.md` names it a test-surface dependency: four
 *     test files implement it because it is the only substitution that drives Laravel AI's real
 *     stream()/resume pipeline with controlled output (Agent::fake() never resumes tools, #233).
 *   streamable-response-generator-seam — `StreamableAgentResponseGenerator` reaches upstream's
 *     PROTECTED `generator` property by reflection and throws if it is not a Closure. Reflection
 *     into a protected member is the most breakable dependency Verdict has, and nothing pinned it.
 *   live-observer-response-taxonomy — `LiveAgentObserver` classifies a run using
 *     ApprovalNotResumableException and the AgentResponse/StreamableAgentResponse/
 *     StructuredAgentResponse trio. "Streaming semantics" was too vague to cover it.
 */
const REQUIRED_CONTRACT_BEHAVIOURS = [
    'agent-identity-across-lifecycle',
    'agent-prompt-fields',
    'approval-decisions-shape',
    'approval-pause',
    'invocation-id-on-tool-invoked',
    'live-observer-response-taxonomy',
    'middleware-pipeline-invocation',
    'resume-mints-distinct-invocation-ids',
    'step-text-gateway',
    'streamable-response-generator-seam',
    'streaming-response-semantics',
    'tool-contract-signatures',
    'tool-name-resolution',
];

/**
 * Behaviours that must be exercised against upstream's REAL runtime.
 *
 * The first two are what ADR 0033 §6 records as currently sidestepped by mocking — the reason this
 * issue exists. The third is the reflection seam: a constructed test cannot tell you whether
 * upstream still holds a Closure in a protected property, which is the only thing that matters.
 */
const MUST_BE_REAL_RUNTIME = [
    'agent-identity-across-lifecycle',
    'middleware-pipeline-invocation',
    'streamable-response-generator-seam',
];

/**
 * Evidence that a test drove the genuine pipeline rather than a hand-built input. Positive
 * criterion, because the ABSENCE of mocks establishes nothing — a test can avoid Mockery and still
 * assert against a value it constructed itself.
 */
const REAL_RUNTIME_MARKERS = [
    // Driving the genuine prompt/tool pipeline...
    'FakeTextGateway',
    'StepTextGateway',
    'Ai::',
    // ...or constructing the real upstream object and inspecting it. For the protected-generator
    // seam a full pipeline is the WRONG shape: what has to hold is that a real
    // StreamableAgentResponse still carries a Closure in that property, and building one directly
    // is the sharpest way to ask.
    'new StreamableAgentResponse',
    'StreamableAgentResponse(',
];

/** Source with comments and docblocks removed — every lexical check below runs on code only. */
function contractCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return array<string, array{behaviour: string, fidelity: string, consequence: string, source: string}> */
function contractSuiteFiles(): array
{
    $dir = dirname(__DIR__).'/Contract';

    if (! is_dir($dir)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match('/@contract-behaviour\s+([a-z0-9-]+)/', $source, $behaviour);
        preg_match('/@contract-fidelity\s+([a-z]+)/', $source, $fidelity);
        // The consequence must carry TEXT, not merely the tag: an empty tag satisfies a presence
        // check while telling a reader nothing about what Verdict loses when this breaks.
        preg_match('/@contract-consequence\s+(\S.*)/', $source, $consequence);

        // Keyed by path relative to tests/, not basename: two files of the same name in different
        // subdirectories would otherwise overwrite one another and silently drop coverage.
        $relative = 'Contract/'.ltrim(str_replace(realpath($dir), '', $file->getPathname()), '/');

        $files[$relative] = [
            'behaviour' => $behaviour[1] ?? '',
            'fidelity' => $fidelity[1] ?? '',
            'consequence' => trim($consequence[1] ?? ''),
            'source' => $source,
        ];
    }

    ksort($files);

    return $files;
}

/** @return array<string, array{behaviour: string, fidelity: string, consequence: string, source: string}> */
function contractSuiteFilesOrFail(): array
{
    $files = contractSuiteFiles();

    // Without this every foreach below asserts nothing when the directory is missing, and Pest
    // reports those tests RISKY rather than failed — green-looking tests that examined no file.
    expect($files)->not->toBe([], 'tests/Contract/ must exist and hold the contract tests.');

    return $files;
}

it('ships a contract suite', function (): void {
    expect(contractSuiteFiles())->not->toBe([]);
});

it('declares a behaviour, a fidelity, and a written consequence on every contract test', function (): void {
    foreach (contractSuiteFilesOrFail() as $name => $file) {
        expect($file['behaviour'])->not->toBe('', "[{$name}] must declare @contract-behaviour.")
            ->and($file['fidelity'])->toBeIn(['real', 'constructed'], "[{$name}] must declare @contract-fidelity real|constructed.")
            // What Verdict depends on the behaviour FOR is the difference between a contract and
            // coverage: it is what turns a failure into a named lost guarantee.
            ->and(strlen($file['consequence']))->toBeGreaterThan(20, "[{$name}] must declare @contract-consequence with actual text.");
    }
});

it('covers the whole behaviour catalogue, and nothing outside it', function (): void {
    $covered = array_values(array_unique(array_map(
        static fn (array $f): string => $f['behaviour'],
        contractSuiteFilesOrFail(),
    )));
    sort($covered);

    // Set equality, not one-file-per-behaviour: streaming, lifecycle and resume behaviours can each
    // justify several independent cases, and forbidding that would push people to cram unrelated
    // assertions into one test to satisfy the counter.
    expect($covered)->toBe(
        REQUIRED_CONTRACT_BEHAVIOURS,
        'The contract suite must cover every catalogued behaviour and declare no slug outside it — '
        .'otherwise the published contract and the enforced one drift apart.',
    );
});

it('actually exercises something in every contract test', function (): void {
    // Closes the emptiest hole: thirteen files carrying nothing but docblocks would satisfy every
    // rule above. A contract test must run a test, assert something, and touch upstream — a test
    // that never names a Laravel\Ai symbol is not pinning laravel/ai's behaviour.
    foreach (contractSuiteFilesOrFail() as $name => $file) {
        $code = contractCode($file['source']);

        // Comments stripped first: a docblock containing the words it( and expect( satisfied the
        // previous version of this check, which is the very shape it existed to forbid.
        //
        // Exactly ONE test per file, so the file-level @contract-fidelity and
        // @contract-consequence are unambiguous. A file with a genuine pipeline smoke test plus
        // three hand-built cases would otherwise carry one honest label over four different
        // fidelities. Several files per behaviour remain fine — the catalogue is a set.
        expect(substr_count($code, 'it('))->toBe(1, "[{$name}] must declare exactly one test, so its fidelity label is unambiguous.")
            ->and(substr_count($code, 'expect('))->toBeGreaterThan(0, "[{$name}] asserts nothing.")
            // str_contains + toBeTrue, not toContain($needle, $message): Pest's toContain() is
            // variadic over EXPECTED VALUES, so a message passed as a second argument becomes a
            // second required needle and the assertion can never pass.
            ->and(str_contains($code, 'Laravel\\Ai'))->toBeTrue("[{$name}] never references an upstream symbol in code.");
    }
});

it('drives the genuine pipeline in every real-fidelity test', function (): void {
    foreach (contractSuiteFilesOrFail() as $name => $file) {
        if ($file['fidelity'] !== 'real') {
            continue;
        }

        $drivesPipeline = false;

        foreach (REAL_RUNTIME_MARKERS as $marker) {
            $drivesPipeline = $drivesPipeline || str_contains(contractCode($file['source']), $marker);
        }

        expect($drivesPipeline)->toBeTrue(
            "[{$name}] is labelled real-runtime but shows no sign of driving Laravel AI's pipeline "
            .'(one of '.implode(', ', REAL_RUNTIME_MARKERS).'). Absence of mocks proves nothing on '
            .'its own — a test can avoid Mockery and still assert against a value it built itself.',
        );
    }
});

it('does not let a real-fidelity test double the subject it claims to pin', function (): void {
    // Narrow, not blanket: doubling an unrelated local collaborator is legitimate. Doubling the
    // upstream participant whose behaviour the test exists to prove is how the current
    // agent-identity coverage came to prove nothing — it hands the same Mockery double to two
    // prompts and asserts identity holds.
    foreach (contractSuiteFilesOrFail() as $name => $file) {
        if ($file['fidelity'] !== 'real') {
            continue;
        }

        $code = contractCode($file['source']);
        $subjects = ['Agent', 'AgentPrompt', 'StreamableAgentResponse'];

        // Resolve aliases first. `use Laravel\Ai\Responses\StreamableAgentResponse as Response;`
        // followed by `Mockery::mock(Response::class)` doubles the subject under a name a literal
        // check never sees — the claimed protection was not actually there until this.
        foreach ($subjects as $upstream) {
            if (preg_match('/use\s+Laravel\\\\Ai\\\\[A-Za-z\\\\]*'.$upstream.'\s+as\s+([A-Za-z_][A-Za-z0-9_]*)/i', $code, $alias) === 1) {
                $subjects[] = $alias[1];
            }
        }

        foreach (array_map(static fn (string $n): string => $n.'::class', $subjects) as $subject) {
            expect($code)->not->toMatch(
                '/mock\s*\(\s*\\\\?[A-Za-z\\\\]*'.preg_quote($subject, '/').'/i',
                "[{$name}] is labelled real-runtime but doubles {$subject}, the upstream participant it pins.",
            );
        }
    }
});

it('requires each behaviour it must prove for real to be labelled real', function (): void {
    $byBehaviour = [];

    foreach (contractSuiteFilesOrFail() as $file) {
        $byBehaviour[$file['behaviour']][] = $file['fidelity'];
    }

    foreach (MUST_BE_REAL_RUNTIME as $behaviour) {
        expect(in_array('real', $byBehaviour[$behaviour] ?? [], true))->toBeTrue(
            "[{$behaviour}] must have at least one real-runtime test. A constructed test here leaves "
            .'the gap open while appearing to close it.',
        );
    }
});

it('replaces the compatibility document\'s caveats rather than deleting them', function (): void {
    // Deleting the caveat and closing the gap look identical to a reader. The document must now
    // NAME the test that closed it, so the claim can be checked rather than believed.
    $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/laravel-ai-compatibility.md');

    expect($doc)->not->toContain('There is currently no test that exercises this assumption')
        ->and($doc)->not->toContain("that's simulated, not exercised")
        ->and(str_contains($doc, 'tests/Contract/'))->toBeTrue('The document must name the contract tests that closed its caveats.');
});

it('runs the contract suite in the canary against both the supported range and the dev branch', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/laravel-ai-canary.yml');
    // symfony/yaml, not the yaml extension: the extension is not installed here, and a test
    // that errors on a missing function asserts nothing about the workflow.
    $parsed = Yaml::parse($workflow);

    expect($parsed)->toBeArray('The canary workflow must remain parseable YAML.');

    $matrix = $parsed['jobs']['canary']['strategy']['matrix'] ?? null;

    // A real matrix with two cells, not two strings that happen to appear in comments: the point is
    // that the suite RUNS twice. Without the supported-range cell a contract failure cannot be told
    // apart — "upstream moved ahead of us" versus "we are already broken on what we ship against".
    expect($matrix)->toBeArray('The canary must declare a strategy.matrix over laravel/ai versions.');

    // Exactly one key, so a decoy first key cannot absorb the exact-values assertion while the
    // real laravel/ai axis goes unconstrained. The key's NAME stays the implementer's choice.
    expect($matrix)->toHaveCount(1, 'The canary matrix must declare exactly one axis, the laravel/ai version.');

    $key = array_key_first($matrix);
    $values = array_map(strval(...), array_values((array) $matrix[$key]));
    sort($values);

    // Exact values, not substrings: '0.11' would be satisfied by '^0.11.0-beta' or a comment, and
    // the supported cell has to be the range composer.json actually ships against.
    expect($values)->toBe(['0.x-dev', '^0.11.0'], 'The canary matrix must hold exactly the supported range and the dev branch.');

    $steps = $parsed['jobs']['canary']['steps'] ?? [];
    $script = implode("\n", array_map(static fn (array $s): string => (string) ($s['run'] ?? ''), $steps));

    // The matrix value must reach Composer, and a cell must actually execute the contract suite.
    // The SAME key must reach Composer — a matrix nothing interpolates changes nothing — and a step
    // must actually execute the contract suite rather than merely mention the path.
    expect($script)->toMatch(
        '/composer\s+require[^\n]*laravel\/ai:\$\{\{\s*matrix\.'.preg_quote((string) $key, '/').'/',
        "The matrix key [{$key}] must be interpolated into the composer require itself — an echo "
        .'mentioning it would otherwise satisfy this while a fixed version was installed.',
    )
        ->and($script)->toMatch(
            '/(vendor\/bin\/pest|composer\s+test)[^\n]*tests\/Contract/',
            'A canary step must execute the contract suite (pest or composer test targeting tests/Contract).',
        );
});
