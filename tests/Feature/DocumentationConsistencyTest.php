<?php

declare(strict_types=1);

/**
 * Guards the adopter-facing facts that drifted between docs and code (rebuttal fixes D1/D2/D9).
 * These are prose invariants no other test covered, which is why they rotted. The predicates below
 * parse structure — the platform-matrix table, caret-form dependency constraints, the disposition
 * token — rather than surrounding wording, so a succinct rewrite can pass without weakening them.
 *
 *  - D1: every laravel/ai version stated in README/RELEASES matches what composer.json pins.
 *  - D2: the platform matrix lists exactly one current line, and it is the shipping VERSION.
 *  - D9: a disposition advertised as model-visible is produced somewhere, or marked reserved.
 */
function verdictRoot(): string
{
    return dirname(__DIR__, 2);
}

function verdictReadContent(string $relative): string
{
    $contents = file_get_contents(verdictRoot().'/'.$relative);

    expect($contents)->not->toBeFalse("Expected to read {$relative}");

    return (string) $contents;
}

/** The major.minor a Composer constraint pins, e.g. "^0.11.0" -> "0.11". */
function verdictPinnedMinor(string $package): string
{
    /** @var array{require?: array<string,string>} $composer */
    $composer = json_decode(verdictReadContent('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $constraint = $composer['require'][$package] ?? '';

    expect($constraint)->toMatch('/^\^?\d+\.\d+/', "composer.json must require {$package}");
    preg_match('/(\d+\.\d+)/', $constraint, $m);

    return $m[1];
}

/** The major.minor of the VERSION file, e.g. "0.12.0" -> "0.12". */
function verdictVersionMinor(): string
{
    preg_match('/(\d+\.\d+)/', trim(verdictReadContent('VERSION')), $m);

    return $m[1];
}

/**
 * Parse the Markdown table under `## Supported platform matrix` in RELEASES.md.
 *
 * @return list<array<string,string>> one map of header => cell per data row
 */
function verdictPlatformMatrixRows(): array
{
    $releases = verdictReadContent('RELEASES.md');

    expect($releases)->toContain('## Supported platform matrix');
    $section = (string) preg_split('/\n## /', substr($releases, (int) strpos($releases, '## Supported platform matrix')))[0];

    $rows = array_values(array_filter(
        array_map('trim', preg_split('/\R/', $section)),
        static fn (string $line): bool => str_starts_with($line, '|'),
    ));

    expect(count($rows))->toBeGreaterThan(2); // header + separator + at least one data row

    $cells = static function (string $line): array {
        $parts = array_map('trim', explode('|', $line));
        array_shift($parts);   // drop the empty field before the leading pipe
        array_pop($parts);     // drop the empty field after the trailing pipe

        return $parts;
    };

    $header = $cells($rows[0]);
    expect($header)->toContain('Verdict line')->toContain('Laravel AI')->toContain('Status');

    $out = [];

    foreach (array_slice($rows, 2) as $dataRow) { // skip header row and the |---| separator
        $out[] = array_combine($header, $cells($dataRow));
    }

    return $out;
}

// ---- D1: every stated laravel/ai version matches the composer pin ----------------------------

it('states a laravel/ai version in the docs that matches the composer pin', function (): void {
    $pinned = verdictPinnedMinor('laravel/ai'); // "0.11" today

    // (a) The platform-matrix "Laravel AI" column. This is the surface the earlier prose-only regex
    //     missed (the header names the column; the versions live in the cells).
    foreach (verdictPlatformMatrixRows() as $row) {
        $cell = $row['Laravel AI'] ?? '';
        preg_match_all('/(\d+\.\d+)/', $cell, $m);

        foreach ($m[1] as $minor) {
            expect($minor)->toBe($pinned, "RELEASES matrix Laravel AI cell names {$minor}.x, composer pins ^{$pinned}");
        }
    }

    // (b) Caret-form dependency declarations in prose (e.g. "Laravel AI `^0.11.0`",
    //     "pins `laravel/ai: ^0.11.0`"). The caret is what distinguishes a version *constraint*
    //     from incidental prose like "Laravel AI is pre-1.0", which must not be matched.
    $declared = static function (string $content): array {
        preg_match_all('/(?:laravel\/ai|Laravel AI)[^\n]{0,40}?\^(\d+\.\d+)/i', $content, $m);

        return $m[1];
    };

    // README is the front door — it must state the requirement, and it must be right.
    $readme = $declared(verdictReadContent('README.md'));
    expect($readme)->not->toBeEmpty('README must declare the laravel/ai constraint');
    foreach ($readme as $minor) {
        expect($minor)->toBe($pinned, "README declares laravel/ai ^{$minor}, composer pins ^{$pinned}");
    }

    // RELEASES may delegate the prose to the compatibility doc, but any caret constraint it *does*
    // state must be right.
    foreach ($declared(verdictReadContent('RELEASES.md')) as $minor) {
        expect($minor)->toBe($pinned, "RELEASES declares laravel/ai ^{$minor}, composer pins ^{$pinned}");
    }
});

// ---- D2: the matrix lists exactly one current line, and it is the shipping VERSION -----------

it('marks exactly the shipping VERSION line as current in the platform matrix', function (): void {
    $versionMinor = verdictVersionMinor(); // "0.12"

    $currentRows = array_values(array_filter(
        verdictPlatformMatrixRows(),
        static fn (array $row): bool => str_contains(strtolower($row['Status'] ?? ''), 'current'),
    ));

    expect($currentRows)->toHaveCount(1, 'The platform matrix must mark exactly one line current');
    expect($currentRows[0]['Verdict line'] ?? '')->toMatch(
        '/(?<!\d)'.preg_quote($versionMinor, '/').'\.x(?!\d)/',
        "The current matrix line must be exactly the shipping line {$versionMinor}.x",
    );
});

// ---- D9: an advertised disposition is produced, or marked reserved ---------------------------

/**
 * src references that PRODUCE Disposition::RequireReview — a call to the factory, or a direct enum
 * use — excluding the enum case's own home and the (currently unused) factory definition.
 *
 * @return list<string>
 */
function verdictRequireReviewProducers(): array
{
    // A producer is `<Verdict Decision>::requireReview` (a call to the factory) or a direct
    // `<Verdict Disposition>::RequireReview`, with the class name RESOLVED to Verdict's own symbol —
    // so a same-named foreign class (Other\Decision) does not count — and excluding the symbol's own
    // definition file. Members are static::member; enum cases here are read the same token shape.
    $targets = [
        ['Fissible\\Verdict\\Decisions\\Decision', 'requireReview', ['Decision.php']],
        ['Fissible\\Verdict\\Decisions\\Disposition', 'RequireReview', ['Decision.php', 'Disposition.php']],
    ];
    $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    $producers = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(verdictRoot().'/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $name = $file->getFilename();

        // Significant tokens only — drop comments and whitespace so a mention in a comment or a
        // string literal cannot masquerade as a producer, and `A :: b` reads the same as `A::b`.
        $sig = [];
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_WHITESPACE], true)) {
                continue;
            }
            $sig[] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
        }

        // Resolve the file's namespace and its use/alias map, so a short class name can be resolved.
        $namespace = '';
        $uses = []; // short name (or alias) => fully-qualified class
        for ($i = 0, $n = count($sig); $i < $n; $i++) {
            if ($sig[$i][0] === T_NAMESPACE && in_array($sig[$i + 1][0] ?? null, $nameTokens, true)) {
                $namespace = $sig[$i + 1][1];
            }
            if ($sig[$i][0] === T_USE && in_array($sig[$i + 1][0] ?? null, $nameTokens, true)) {
                $fqcn = ltrim($sig[$i + 1][1], '\\');
                $alias = (($sig[$i + 2][0] ?? null) === T_AS && ($sig[$i + 3][0] ?? null) === T_STRING)
                    ? $sig[$i + 3][1]
                    : substr((string) strrchr('\\'.$fqcn, '\\'), 1);
                $uses[$alias] = $fqcn;
            }
        }

        $resolve = static function (string $written) use ($namespace, $uses): string {
            if (str_starts_with($written, '\\')) {
                return ltrim($written, '\\'); // already fully-qualified
            }
            $first = explode('\\', $written)[0];
            if (isset($uses[$first])) {
                return $uses[$first].substr($written, strlen($first)); // imported / aliased
            }

            return ($namespace !== '' ? $namespace.'\\' : '').$written; // relative to this namespace
        };

        for ($i = 0, $n = count($sig) - 2; $i < $n; $i++) {
            if (! in_array($sig[$i][0], $nameTokens, true) || ($sig[$i + 1][0] ?? null) !== T_DOUBLE_COLON || ($sig[$i + 2][0] ?? null) !== T_STRING) {
                continue;
            }
            $fqcn = $resolve($sig[$i][1]);
            $member = $sig[$i + 2][1];

            foreach ($targets as [$targetClass, $targetMember, $excludedFiles]) {
                if ($fqcn === $targetClass && $member === $targetMember && ! in_array($name, $excludedFiles, true)) {
                    $producers[] = $file->getPathname();
                    break 2;
                }
            }
        }
    }

    return array_values(array_unique($producers));
}

it('does not advertise require_review as a live disposition while nothing produces it', function (): void {
    if (verdictRequireReviewProducers() !== []) {
        expect(true)->toBeTrue(); // A path returns it — advertising it is honest.

        return;
    }

    // No producer: EVERY README paragraph that mentions the token must mark it reserved/planned.
    // (If the fix removes the mention entirely, no paragraph matches and this passes.)
    $paragraphs = preg_split('/\n\s*\n/', verdictReadContent('README.md'));

    foreach ($paragraphs as $paragraph) {
        if (! str_contains($paragraph, 'require_review')) {
            continue;
        }

        expect($paragraph)->toMatch(
            '/reserved|planned|not yet|#297/i',
            'README advertises require_review but nothing produces Disposition::RequireReview — mark it reserved (#297) or remove it',
        );
    }
});

/**
 * The framing claim must never travel alone.
 *
 * Verdict bounds authority, duplication, rate and approval. What a reader hears in "security
 * boundary" is a fifth thing it does not bound — intent: under prompt injection the actor is the
 * legitimate authenticated user, so an injected instruction selecting any record inside that
 * user's own authority passes every check by design.
 *
 * That is not a documentation bug a paragraph elsewhere fixes. `docs/limitations.md` and
 * `docs/security-model.md` already say it thoroughly, and the gap is one click away — but the
 * top-line claim is the part doing the most unsupervised work, because it is what gets quoted
 * without the surrounding material. So the qualifier ships WITH the claim, and this test is what
 * stops the two drifting apart: move the claim, and the sentence naming the bounds moves with it.
 *
 * The recorded decision behind this is [ADR 0034](../../docs/adr/0034-the-framing-claim-never-travels-alone.md),
 * which weighed narrowing the category to "authorization boundary" and kept it on condition that it
 * never appears unqualified. Narrowing remains defensible pre-1.0; if it is ever taken, this test is
 * where the new framing gets its qualifier.
 */
it('never states the framing claim without naming the four bounds and the one non-bound', function (): void {
    $readme = verdictReadContent('README.md');
    $paragraphs = preg_split('/\n\s*\n/', $readme) ?: [];

    $claim = null;

    foreach ($paragraphs as $index => $paragraph) {
        if (str_contains($paragraph, 'security boundary for AI-triggered application actions')) {
            // The claim's own paragraph plus the one after it — the qualifier may share the line or
            // follow it, and which of the two is a matter of prose rather than of contract.
            $claim = $paragraph."\n\n".($paragraphs[$index + 1] ?? '');

            break;
        }
    }

    expect($claim)->not->toBeNull('README must state what Verdict is');

    // Each of the four bounds by name. `toContain()` takes needles rather than a message, so the
    // reason lives here: a claim that names three of four is the same over-promise in miniature.
    expect(strtolower((string) $claim))->toContain('authority', 'duplication', 'rate', 'approval');

    // And the non-bound, which is the whole reason the qualifier exists.
    expect(strtolower((string) $claim))->toContain('intent')
        ->and(strtolower((string) $claim))->toMatch('/does not bound|not what it was trying/');
});
