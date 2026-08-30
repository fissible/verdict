<?php

declare(strict_types=1);

use Composer\Semver\Semver;
use Symfony\Component\Process\Process;

/**
 * What makes the compatibility matrix HONEST rather than merely present (#341, ADR 0033).
 *
 * The failure this issue names is specific: "a row asserting more than was actually verified is
 * worse than no row, because it will be trusted." A matrix is a support claim, and it goes wrong
 * not by being absent but by staying on the page after it stopped being true.
 *
 * Four decisions shape the rules, and each closes one way the document could rot:
 *
 *   EVERY ROW NAMES A RELEASED VERDICT TAG. "Identifiable released artifact" is checkable offline
 *   from `git tag`, with no network call and no flakiness. Pinning rows to the VERSION file instead
 *   would deadlock the release: release.sh writes VERSION and commits before the tag exists, and at
 *   that moment no CI run has yet tested the new release — so regenerating there would fabricate
 *   evidence rather than record it. A row may therefore lag a release, which is true and is what
 *   the date column discloses.
 *
 *   VERDICT IS THE ANCHOR. Every row's laravel/ai version must satisfy the constraint this package
 *   declares in composer.json. That single rule is also the retirement rule: bumping the constraint
 *   mechanically invalidates every row that no longer qualifies, so pruning is forced by a test
 *   rather than left to somebody's memory, and the obligation stays local to this repository — one
 *   that depends on another repo's cadence is one nobody owns.
 *
 *   THE TABLE IS GENERATED. A hand-written table is a promise to remember. What makes "generated"
 *   mean anything is not that a script exists but that the committed block EQUALS what the script
 *   produces, and that the script derives it from a facts input rather than echoing the document
 *   back. A generator nobody diffs is a hand-written table with extra steps.
 *
 *   A ROW CARRIES EVIDENCE, NOT A VERDICT. "Known good" is at minimum tested once, but a CI sweep
 *   and one local check are different claims. Each row names its verification KIND from a closed
 *   vocabulary and a REFERENCE that outlives the person who ran it — for CI, the run itself.
 *
 * These are meta-tests. They constrain the shape and auditability of the document, never the truth
 * of its cells: nothing here can tell you whether a row's claim is correct, only whether the row
 * reports a checkable observation instead of asserting a conclusion. Judging the claim is the
 * reviewer's job, and no rule below is a substitute for reading the table.
 *
 * Where a rule can be derived from the tree it is, rather than restated as a literal — a rule that
 * hard-codes the expected answer keeps passing after the answer changes.
 */

/** The document under specification. */
const COMPATIBILITY_DOC = 'docs/laravel-ai-compatibility.md';

/**
 * The generator. Pinned by path so the document, CI, and this test agree on what "regenerate it"
 * means without each naming its own; a reader who finds the table should be one `ls` from the thing
 * that produced it.
 */
const COMPATIBILITY_GENERATOR = 'scripts/generate-compatibility-matrix.php';

/**
 * Delimiters around the generated region. Exact-equality reproducibility needs an unambiguous
 * boundary: without one the check degrades to "the document contains this substring somewhere",
 * which a generator emitting a single common word would satisfy.
 */
const COMPATIBILITY_BLOCK_OPEN = '<!-- generated:compatibility-matrix -->';
const COMPATIBILITY_BLOCK_CLOSE = '<!-- /generated:compatibility-matrix -->';

/** The three subjects the matrix exists to relate. */
const COMPATIBILITY_SUBJECTS = ['verdict', 'verdict-console', 'laravel/ai'];

/**
 * The table's exact columns, in order. A fixed schema rather than "any non-subject column is
 * evidence": free-text prose columns were satisfiable by a sentence containing the right words, and
 * an adopter cannot read a tested environment out of a paragraph. Structured cells are also what
 * lets the rules below check each field for what that field specifically must be.
 */
const COMPATIBILITY_SCHEMA = [
    'verdict', 'verdict-console', 'laravel/ai', 'php', 'laravel', 'verified', 'date', 'evidence',
];

/**
 * How a row may have been verified. Closed and matched as the cell's WHOLE value, because the
 * distinction is the point: `ci` is a sweep whose run can be reopened years later, `local` is one
 * person once. A free-text search matched "local" inside `local.example` and inside URLs.
 */
const COMPATIBILITY_VERIFICATION_KINDS = ['ci', 'local'];

/** A CI claim must reference the run, which is the part that outlives everyone involved. */
const COMPATIBILITY_CI_REFERENCE = '#https://github\.com/[\w.-]+/[\w.-]+/actions/runs/\d+#';

/**
 * A `local` claim cannot reference a CI run, but it still has to point at something an adopter can
 * open. Either an immutable GitHub permalink, or a repository-relative path pinned to a revision —
 * "local | done" satisfies a non-empty check while giving a reader nothing to inspect.
 */
const COMPATIBILITY_LOCAL_REFERENCE = '#^(?:https://github\.com/[\w.-]+/[\w.-]+/(?:commit|blob|tree)/[0-9a-f]{7,40}(?:/[\w./-]*)?|[\w-]+(?:/[\w.-]+)*@[0-9a-f]{7,40})$#';

/**
 * Statements the matrix section must make. Presence of a policy, not its quality — no assertion can
 * check that prose means what it says, so these require the specific terms to CO-OCCUR rather than
 * accepting a keyword that could appear in unrelated text.
 */
const COMPATIBILITY_REQUIRED_STATEMENTS = [
    'retirement criterion (the composer constraint)' => '/(?:retire|removed?|prune)[^.]{0,160}composer\.json|composer\.json[^.]{0,160}(?:retire|removed?|prune)/is',
    'regeneration trigger (a constraint change)' => '/constraint[^.]{0,160}chang|chang[^.]{0,160}constraint/is',
];

/**
 * Satisfies #341's fallback branch: if the console column is deferred, the omission must be stated
 * so it is not read as "no console support".
 */
const COMPATIBILITY_DEFERRAL_MARKERS = '/\bdeferred\b|\bnot yet (?:published|listed|recorded)\b/i';

/**
 * If console facts are published, the document must say Verdict did not verify them: Verdict's CI
 * cannot run console's suite, and an unmarked column sitting beside two generated-and-verified ones
 * is exactly how a reader over-reads it.
 */
const COMPATIBILITY_CONSOLE_PROVENANCE = '/\breported by\b|\bsupplied by\b|\bnot verified (?:here|by)\b/i';

function compatibilityRepositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function compatibilityDocument(): string
{
    $path = compatibilityRepositoryRoot().'/'.COMPATIBILITY_DOC;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array{require: array<string, string>} */
function compatibilityComposerManifest(): array
{
    /** @var array{require: array<string, string>} $manifest */
    $manifest = json_decode((string) file_get_contents(compatibilityRepositoryRoot().'/composer.json'), true);

    return $manifest;
}

function compatibilityDeclaredVersion(): string
{
    return trim((string) file_get_contents(compatibilityRepositoryRoot().'/VERSION'));
}

/**
 * A named section's body: the first heading whose text matches $needle, up to the next heading of
 * the same or higher level.
 */
function compatibilityNamedSection(string $needle): ?string
{
    $document = compatibilityDocument();

    if ($document === '' || preg_match('/^(#{2,6})\s*.*'.preg_quote($needle, '/').'.*$/mi', $document, $heading, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $level = strlen($heading[1][0]);
    $rest = substr($document, (int) $heading[0][1] + strlen($heading[0][0]));

    $end = preg_match('/^#{1,'.$level.'}\s/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1
        ? (int) $next[0][1]
        : strlen($rest);

    return substr($rest, 0, $end);
}

/** The matrix section, located by what it is FOR rather than by exact wording. */
function compatibilityMatrixSection(): ?string
{
    return compatibilityNamedSection('compatibility matrix');
}

/**
 * The contents of the single generated block, delimiters excluded.
 *
 * @return array{content: string, count: int}
 */
function compatibilityGeneratedBlock(): array
{
    $document = compatibilityDocument();
    $pattern = '/'.preg_quote(COMPATIBILITY_BLOCK_OPEN, '/').'(.*?)'.preg_quote(COMPATIBILITY_BLOCK_CLOSE, '/').'/s';

    $count = preg_match_all($pattern, $document, $matches);

    return ['content' => $count >= 1 ? trim($matches[1][0]) : '', 'count' => (int) $count];
}

/**
 * The matrix's header cells and data rows, parsed from the generated block so a hand-written table
 * elsewhere in the document cannot stand in for the generated one.
 *
 * @return array{header: list<string>, rows: list<list<string>>}|null
 */
function compatibilityMatrixTable(): ?array
{
    $block = compatibilityGeneratedBlock()['content'];

    if ($block === '') {
        return null;
    }

    $header = null;
    $rows = [];

    foreach (explode("\n", $block) as $line) {
        $line = trim($line);

        if (! str_starts_with($line, '|')) {
            if ($header !== null && $line !== '') {
                break;
            }

            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));

        // The alignment row (|---|---|) is structure, not data.
        if ($cells !== [] && preg_match('/^:?-{3,}:?$/', $cells[0]) === 1) {
            continue;
        }

        if ($header === null) {
            $header = $cells;

            continue;
        }

        $rows[] = $cells;
    }

    return $header === null ? null : ['header' => $header, 'rows' => $rows];
}

/**
 * Shared non-empty guard. Every looping rule runs through this, because a loop over an empty table
 * asserts nothing and Pest reports it RISKY rather than failed — an absent matrix would otherwise
 * satisfy every rule that describes one.
 *
 * @return array{header: list<string>, rows: list<list<string>>}
 */
function compatibilityMatrixTableOrFail(): array
{
    $table = compatibilityMatrixTable();

    expect($table)->not->toBeNull(COMPATIBILITY_DOC.' has no generated compatibility-matrix block containing a table.');
    expect($table['rows'])->not->toBeEmpty('The compatibility matrix has a header but no rows.');

    return $table;
}

/**
 * Index of the column whose heading names $subject, matched on the heading's exact identity so
 * "verdict" does not also select "verdict-console".
 */
function compatibilityColumn(array $header, string $subject): ?int
{
    foreach ($header as $position => $heading) {
        $heading = strtolower(trim($heading, ' `*'));

        if ($heading === $subject || $heading === 'fissible/'.$subject) {
            return $position;
        }
    }

    return null;
}

/** A row keyed by the schema's column names. */
function compatibilityRowFields(array $row): array
{
    return array_combine(COMPATIBILITY_SCHEMA, array_slice($row, 0, count(COMPATIBILITY_SCHEMA)));
}

/**
 * Released Verdict tags, read from git. Offline, deterministic, and the honest answer to "is this a
 * real release" — unlike a Packagist lookup, which would make a docs rule a flaky network test.
 *
 * @return list<string>
 */
function compatibilityReleasedTags(): array
{
    $process = new Process(['git', 'tag', '--list', 'v*'], compatibilityRepositoryRoot());
    $process->run();

    return array_values(array_filter(array_map(
        static fn (string $tag): string => ltrim(trim($tag), 'v'),
        explode("\n", $process->getOutput())
    )));
}

/**
 * Files under `src/` referencing Laravel AI's approval-decision vocabulary, computed from the tree.
 * ADR 0033 §2 moved this symbol behind the adapter and the document's job is to record where it
 * lives, so the expected answer is read from `src/` rather than restated here.
 *
 * @return list<string>
 */
function compatibilityDecisionsCallSites(): array
{
    $root = compatibilityRepositoryRoot().'/src';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'Laravel\\Ai\\Approvals\\Decision')) {
            // Normalise separators on BOTH sides before stripping the prefix. On Windows realpath()
            // returns backslashes while the iterator yields a mixed path, so a raw str_replace finds
            // no prefix to remove and leaves an absolute path in what must be a repository-relative
            // one — which fails this rule for a reason that has nothing to do with the code it guards.
            $path = str_replace('\\', '/', (string) $file->getRealPath());
            $prefix = str_replace('\\', '/', (string) realpath($root));

            $found[] = 'src/'.ltrim(substr($path, strlen($prefix)), '/');
        }
    }

    sort($found);

    return $found;
}

/**
 * The symbol-inventory row for a given Laravel AI symbol, as its cells. Scoped to the inventory
 * section: scanning the whole document let an unrelated table elsewhere satisfy the rule.
 */
function compatibilityInventoryRow(string $symbol): ?array
{
    $inventory = compatibilityNamedSection('symbol inventory');

    if ($inventory === null) {
        return null;
    }

    foreach (explode("\n", $inventory) as $line) {
        $line = trim($line);

        if (! str_starts_with($line, '|') || ! str_contains($line, $symbol)) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));

        // The symbol must BE the row's subject, not a substring of a longer symbol and not a
        // passing mention in a Notes cell.
        if ($cells !== [] && trim($cells[0], ' `*') === $symbol) {
            return $cells;
        }
    }

    return null;
}

it('publishes exactly one generated matrix on the declared schema', function (): void {
    $document = compatibilityDocument();

    // Unmatched delimiters would let a stray opener swallow the rest of the document, or leave a
    // second table looking generated when nothing produced it.
    expect(substr_count($document, COMPATIBILITY_BLOCK_OPEN))->toBe(1, 'Expected exactly one opening matrix delimiter.');
    expect(substr_count($document, COMPATIBILITY_BLOCK_CLOSE))->toBe(1, 'Expected exactly one closing matrix delimiter.');

    $block = compatibilityGeneratedBlock();

    expect($block['count'])->toBe(1, 'The delimiters do not enclose exactly one block.');

    $table = compatibilityMatrixTableOrFail();

    // The separator must be the line immediately after the header, with one cell per column.
    // Accepting one anywhere in the block let pipes in prose stand in for a table.
    $lines = array_values(array_filter(array_map('trim', explode("\n", $block['content'])), fn (string $l): bool => $l !== ''));
    $separator = $lines[1] ?? '';

    expect(preg_match('/^\|(?:\s*:?-{3,}:?\s*\|){'.count(COMPATIBILITY_SCHEMA).'}$/', $separator))->toBe(
        1,
        'The line after the header is not a separator row with '.count(COMPATIBILITY_SCHEMA).' cells; got "'.$separator.'".'
    );

    // The exact schema, in order. Free-form columns let a prose sentence stand in for evidence and
    // let a duplicate heading be counted as an observation column.
    expect(array_map(static fn (string $h): string => strtolower(trim($h, ' `*')), $table['header']))->toBe(
        COMPATIBILITY_SCHEMA,
        'The matrix header does not match the declared schema.'
    );

    foreach ($table['rows'] as $index => $row) {
        expect(count($row))->toBe(
            count($table['header']),
            'Matrix row '.($index + 1).' has '.count($row).' cells against '.count($table['header']).' headers; a ragged row drops a column without saying so.'
        );

        foreach (compatibilityRowFields($row) as $column => $value) {
            expect(trim($value, ' `*'))->not->toBeEmpty(
                'Matrix row '.($index + 1).' leaves "'.$column.'" empty.'
            );
        }
    }
});

it('names a released verdict tag in every row', function (): void {
    $table = compatibilityMatrixTableOrFail();
    $released = compatibilityReleasedTags();

    expect($released)->not->toBeEmpty('No Verdict tags found, so no row can be checked against a real release.');

    foreach ($table['rows'] as $index => $row) {
        $named = ltrim(trim(compatibilityRowFields($row)['verdict'], ' `*'), 'v');

        expect(in_array($named, $released, true))->toBeTrue(
            'Matrix row '.($index + 1).' names Verdict '.$named.', which is not a released tag. A matrix records what was observed against a real release.'
        );
        expect(version_compare($named, compatibilityDeclaredVersion(), '<='))->toBeTrue(
            'Matrix row '.($index + 1).' names Verdict '.$named.', ahead of the VERSION file.'
        );
    }
});

it('keeps every row inside the laravel/ai constraint this package declares', function (): void {
    $table = compatibilityMatrixTableOrFail();
    $constraint = compatibilityComposerManifest()['require']['laravel/ai'];

    foreach ($table['rows'] as $index => $row) {
        $cell = trim(compatibilityRowFields($row)['laravel/ai'], ' `*');

        preg_match_all('/\bv?(\d+\.\d+\.\d+)\b/', $cell, $matches);

        expect($matches[1])->not->toBeEmpty(
            'Matrix row '.($index + 1).' names no concrete laravel/ai version, so nothing about it can be checked against the constraint.'
        );

        foreach ($matches[1] as $version) {
            expect(Semver::satisfies($version, $constraint))->toBeTrue(
                'Matrix row '.($index + 1).' names laravel/ai '.$version.', outside this package\'s declared constraint '.$constraint.'. Verdict is the anchor: a row falling outside it is retired, not kept.'
            );
        }
    }
});

it('carries a verification kind, a real date, and a reference that outlives its author', function (): void {
    $table = compatibilityMatrixTableOrFail();

    foreach ($table['rows'] as $index => $row) {
        $fields = compatibilityRowFields($row);
        $kind = strtolower(trim($fields['verified'], ' `*'));

        // The WHOLE cell, not a word found somewhere: a free-text search matched "local" inside
        // local.example and inside URLs.
        expect(in_array($kind, COMPATIBILITY_VERIFICATION_KINDS, true))->toBeTrue(
            'Matrix row '.($index + 1).' states verification "'.$kind.'", not one of '.implode(', ', COMPATIBILITY_VERIFICATION_KINDS).'. A swept CI matrix and one local run are different claims.'
        );

        $date = trim($fields['date'], ' `*');

        expect(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts))->toBe(
            1,
            'Matrix row '.($index + 1).' has date "'.$date.'", which is not an ISO date.'
        );
        expect(checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))->toBeTrue(
            'Matrix row '.($index + 1).' has date "'.$date.'", which is not a real calendar date.'
        );

        // Both kinds need a durable reference; only CI can be held to a run URL.
        $evidence = trim($fields['evidence'], ' `*');

        if ($kind === 'ci') {
            expect(preg_match(COMPATIBILITY_CI_REFERENCE, $evidence))->toBe(
                1,
                'Matrix row '.($index + 1).' claims CI verification with no run reference. The run is what makes the claim auditable rather than remembered.'
            );
        } else {
            // Anchored end to end: an unterminated alternative let any prose containing seven hex
            // characters pass as a permalink.
            expect(preg_match(COMPATIBILITY_LOCAL_REFERENCE, $evidence))->toBe(
                1,
                'Matrix row '.($index + 1).' claims local verification with no inspectable locator. Point at a permalink or a path@revision; "done" is not evidence.'
            );
            expect(str_contains($evidence, '..'))->toBeFalse(
                'Matrix row '.($index + 1).' has a traversing evidence path.'
            );
        }

        // A tested environment an adopter can act on, per row rather than as prose in the section —
        // and bound to what this package actually declares, or "php 0.0" would satisfy the rule and
        // yield a formally complete, unusable matrix.
        $require = compatibilityComposerManifest()['require'];

        foreach (['php' => $require['php'], 'laravel' => $require['illuminate/contracts']] as $environment => $constraint) {
            preg_match_all('/\b(\d+\.\d+(?:\.\d+)?)\b/', trim($fields[$environment], ' `*'), $found);

            expect($found[1])->not->toBeEmpty(
                'Matrix row '.($index + 1).' names no '.$environment.' version, so the environment it was tested on is unknowable.'
            );

            foreach ($found[1] as $version) {
                $normalised = substr_count($version, '.') === 1 ? $version.'.0' : $version;

                expect(Semver::satisfies($normalised, $constraint))->toBeTrue(
                    'Matrix row '.($index + 1).' names '.$environment.' '.$version.', outside this package\'s declared '.$constraint.'. A row cannot report testing on an environment this package does not support.'
                );
            }
        }
    }
});

it('reproduces the generated block exactly, from a facts input rather than from itself', function (): void {
    compatibilityMatrixTableOrFail();

    $generator = compatibilityRepositoryRoot().'/'.COMPATIBILITY_GENERATOR;

    expect(is_file($generator))->toBeTrue(
        'The document claims a generated table but '.COMPATIBILITY_GENERATOR.' does not exist.'
    );

    // A generator that reads the document it emits cannot detect that the document is wrong; it
    // launders whatever is already there into apparent freshness.
    expect(str_contains((string) file_get_contents($generator), COMPATIBILITY_DOC))->toBeFalse(
        'The generator reads '.COMPATIBILITY_DOC.'. It must derive the table from a facts input, not echo the document back.'
    );

    $process = new Process(['php', COMPATIBILITY_GENERATOR], compatibilityRepositoryRoot());
    $process->run();

    expect($process->getExitCode())->toBe(0, 'The generator failed: '.$process->getErrorOutput());

    // It must genuinely DERIVE the table from the facts input. Rejecting a missing path only proves
    // the option is validated; a generator with the table hard-coded in PHP would pass that and
    // every reproducibility check alongside it. So: take its own facts file, change one byte, and
    // require the output to follow.
    $pathProcess = new Process(['php', COMPATIBILITY_GENERATOR, '--facts-path'], compatibilityRepositoryRoot());
    $pathProcess->run();

    expect($pathProcess->getExitCode())->toBe(
        0,
        'The generator does not report its facts input via --facts-path, so its dependence on one cannot be checked.'
    );

    $factsPath = compatibilityRepositoryRoot().'/'.trim($pathProcess->getOutput());

    expect(is_file($factsPath))->toBeTrue('The generator reports a facts input that does not exist: '.$factsPath);

    // The mutation must stay VALID, or a generator that correctly rejects damaged input produces
    // empty output, which differs from the real output and passes this rule for the wrong reason.
    // So: decode, move one rendered ISO date forward a day, re-encode as valid JSON.
    $facts = json_decode((string) file_get_contents($factsPath), true);

    expect(is_array($facts))->toBeTrue('The facts input is not JSON, so it cannot be mutated safely for this check.');

    // Mutate a date the canonical output actually RENDERS. Mutating the first date found anywhere
    // in the JSON would fail a generator that legitimately ignores an unrendered field — the check
    // would then be measuring the wrong thing while looking like it caught something.
    preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $process->getOutput(), $rendered);
    $renderedDates = array_flip($rendered[0]);

    expect($renderedDates)->not->toBeEmpty('The generated block renders no date, so this check has nothing to trace from input to output.');

    $bumped = false;
    array_walk_recursive($facts, function (&$value) use (&$bumped, $renderedDates): void {
        if (! $bumped && is_string($value) && isset($renderedDates[$value]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            // Add a day rather than subtracting a year: 2024-02-29 minus a year is 2023-02-29,
            // which a correctly validating generator would reject, failing this for the wrong reason.
            $value = (new DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d');
            $bumped = true;
        }
    });

    expect($bumped)->toBeTrue('No date in the facts input appears in the generated output, so the table cannot be shown to derive from it.');

    $mutated = tempnam(sys_get_temp_dir(), 'verdict-facts');
    file_put_contents($mutated, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $withMutated = new Process(['php', COMPATIBILITY_GENERATOR, '--facts='.$mutated], compatibilityRepositoryRoot());
    $withMutated->run();
    unlink($mutated);

    expect($withMutated->getExitCode())->toBe(
        0,
        'The generator rejected a schema-valid facts input: '.$withMutated->getErrorOutput()
    );
    expect($withMutated->getOutput())->not->toBe(
        $process->getOutput(),
        'The generator produced identical output from a different facts input, so it is not deriving the table from one.'
    );

    // Exact equality against the delimited block. Substring containment would pass for a generator
    // that printed a single common word.
    expect(trim($process->getOutput()))->toBe(
        compatibilityGeneratedBlock()['content'],
        'The committed block is not what the generator currently produces. Regenerate it rather than editing the table by hand.'
    );
});

it('states the retirement criterion and the regeneration trigger', function (): void {
    compatibilityMatrixTableOrFail();
    $section = compatibilityMatrixSection() ?? '';

    foreach (COMPATIBILITY_REQUIRED_STATEMENTS as $decision => $pattern) {
        expect($section)->toMatch($pattern, 'The matrix section does not state its '.$decision.'.');
    }

    expect(str_contains($section, COMPATIBILITY_GENERATOR))->toBeTrue(
        'The matrix section does not name '.COMPATIBILITY_GENERATOR.', so a reader cannot tell how to regenerate it.'
    );
});

it('binds console provenance to the rows that carry console facts', function (): void {
    $table = compatibilityMatrixTableOrFail();
    $published = false;

    foreach ($table['rows'] as $index => $row) {
        $cell = trim(compatibilityRowFields($row)['verdict-console'], ' `*');

        if (preg_match(COMPATIBILITY_DEFERRAL_MARKERS, $cell) === 1) {
            continue;
        }

        $published = true;

        // A version identifies; it does not attest. The reference is what ties the claim to
        // something immutable in a repository Verdict's CI cannot run.
        expect(preg_match('/\bv?\d+\.\d+\.\d+\b/', $cell))->toBe(
            1,
            'Matrix row '.($index + 1).' names no concrete verdict-console version.'
        );
        expect(preg_match('#https?://|\b[0-9a-f]{7,40}\b#', $cell))->toBe(
            1,
            'Matrix row '.($index + 1).' publishes a console fact with no reference to a console tag, commit, or run. Verdict cannot verify it, so it must at least be traceable.'
        );
    }

    if ($published) {
        expect(compatibilityMatrixSection() ?? '')->toMatch(
            COMPATIBILITY_CONSOLE_PROVENANCE,
            'Console facts are published without saying where they came from. Verdict\'s CI cannot run console\'s suite, and an unmarked column reads as verified here.'
        );
    }
});

it('records the approval-decision symbol at the call sites src/ actually has', function (): void {
    $callSites = compatibilityDecisionsCallSites();

    // Assert the fixture before asserting against it: if this moved, the rule below is asking the
    // wrong question, and the failure should say so here rather than as a confusing doc mismatch.
    expect($callSites)->toBe(
        ['src/LaravelAi/LaravelApprovalDecisions.php'],
        'ADR 0033 §2 puts Laravel AI approval vocabulary behind the adapter alone. If that moved, this rule and the document both need revisiting.'
    );

    $row = compatibilityInventoryRow('Approvals\\Decisions');

    expect($row)->not->toBeNull('The symbol inventory has no row for Approvals\\Decisions.');

    $whereUsed = implode(' ', array_slice($row, 1));

    // The row must name every current call site and nothing that no longer is one — derived from
    // the tree, so the rule cannot rot into checking for one stale literal.
    foreach ($callSites as $site) {
        $class = basename($site, '.php');

        expect(str_contains($whereUsed, $class))->toBeTrue(
            'The Approvals\\Decisions inventory row does not name '.$class.', which references the symbol today.'
        );
    }

    foreach (['ApprovalExecutionContext', 'VerdictApprovalMiddleware', 'AbstractVerdictTool'] as $former) {
        expect(str_contains($whereUsed, $former))->toBeFalse(
            'The Approvals\\Decisions inventory row still names '.$former.', which no longer references the symbol. The row sends a reader to code that does not contain what it claims.'
        );
    }
});

it('attributes the wildcard special case to the code that performs it', function (): void {
    $adapter = (string) file_get_contents(compatibilityRepositoryRoot().'/src/LaravelAi/LaravelApprovalDecisions.php');
    $section = compatibilityNamedSection('wildcard');

    expect($section)->not->toBeNull(
        'The wildcard approval-decision discussion is gone; it documents live behaviour and should move, not disappear.'
    );

    // The line moved verbatim when ADR 0033 introduced the adapter, so quoting it correctly proves
    // nothing about attribution — the section has to credit the class that now runs it.
    expect(str_contains($section, 'LaravelApprovalDecisions'))->toBeTrue(
        'The wildcard section does not name the class that performs the special case.'
    );
    expect(str_contains($section, 'ApprovalExecutionContext'))->toBeFalse(
        'The wildcard section still credits ApprovalExecutionContext, which no longer sees Laravel AI decisions at all.'
    );

    // Any source line the section quotes must exist in the file it credits. Derived, not restated:
    // a quoted line that drifted from the code is what this catches.
    if (preg_match('/`(if \(\$toolCallId[^`]*)`/', $section, $quoted) === 1) {
        expect(str_contains($adapter, $quoted[1]))->toBeTrue(
            'The section quotes a wildcard-handling line that does not appear in LaravelApprovalDecisions.'
        );
    }
});

it('attributes the edited-decision refusal to the code that performs it', function (): void {
    $adapter = (string) file_get_contents(compatibilityRepositoryRoot().'/src/LaravelAi/LaravelApprovalDecisions.php');
    $section = compatibilityNamedSection('edited');

    expect($section)->not->toBeNull(
        'The edited-approval refusal is undocumented. It is live behaviour — a rejected, not silently dropped, decision — and must be stated where the wildcard case is.'
    );
    expect(str_contains($section, 'LaravelApprovalDecisions'))->toBeTrue(
        'The edited-decision section does not name the class that performs the refusal.'
    );
    expect(str_contains($section, 'UnsupportedApprovalDecision'))->toBeTrue(
        'The edited-decision section does not name the exception the refusal throws, which is what an adopter must catch.'
    );

    // Symbols are not behaviour: the section must actually say an edit is refused, not merely mention
    // the class and the exception in passing.
    expect(preg_match('/\b(reject|refus|throw|unsupported)/i', $section))->toBe(
        1,
        'The edited-decision section names the symbols but never states that the decision is refused.'
    );

    // Attribution must be real: the credited adapter must actually THROW the exception, not merely
    // import, mention, or hold a dead reference to it. Match a throw of the class directly or via a
    // named constructor.
    expect(preg_match('/throw\s+(new\s+UnsupportedApprovalDecision\b|UnsupportedApprovalDecision::)/', $adapter))->toBe(
        1,
        'The section credits LaravelApprovalDecisions, but that class does not throw UnsupportedApprovalDecision.'
    );
});
