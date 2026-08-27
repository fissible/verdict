<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\VerdictManager;

/**
 * ADR 0033: laravel/ai is reached through two declared adapter zones, never from the kernel.
 *
 * laravel/ai is a first-party 0.x package with no stability promise, and Verdict's interception
 * sits in its security path. The zones bound the blast radius of an upstream change; this test is
 * what keeps the boundary true, because a rule nothing enforces is a comment.
 *
 * The exceptions are MEMBER-LEVEL, expressed as exact occurrence counts. Allowing these files
 * wholesale would let upstream references accumulate in them over time, which is how a boundary
 * rule dies quietly — so a new symbol, or one more use of a permitted one, fails here.
 */

/** Zones where upstream types may be named at all, with the reason each exists. */
const LARAVEL_AI_ZONES = [
    // The interception adapter: tool wrappers, middleware, event listeners.
    'src/LaravelAi/',
    // The harness adapter. It exists to drive a REAL agent and classify what it did, so it cannot
    // be written in a vocabulary that excludes upstream — LiveAgentObserver distinguishes a
    // decline from a pause from a failure using upstream's own exception and response types.
    'src/Evaluation/',
];

/**
 * Files outside every zone that may still name upstream types, and exactly how many times.
 *
 * Counts, not just symbol names: a count catches a second use of an already-permitted symbol,
 * which a name-only allowlist would wave through.
 */
const LARAVEL_AI_EXCEPTIONS = [
    // The designated seam (ADR 0033 §4): guard()/bound() take a Tool and hand it to the adapter
    // without ever dereferencing it, and their context resolvers are documented as
    // callable(Request). Moving these would break 63 direct callers for no containment.
    //
    // Two measurements, because neither alone is enough:
    //   `fqcns`    — exact occurrences of each fully-qualified name. Catches substituting a
    //                different upstream type, and covers docblock-only references like the facade's.
    //   `codeUses` — exact whole-word occurrences of each SHORT name with COMMENTS STRIPPED.
    //                Catches a bare extra use of an already-imported symbol, which an FQCN count
    //                cannot see. Comments are stripped so that prose mentioning a type name in an
    //                explanation cannot break the boundary contract.
    'src/VerdictManager.php' => [
        'fqcns' => ['Laravel\Ai\Contracts\Tool' => 1, 'Laravel\Ai\Tools\Request' => 1],
        // Identifier tokens only, so an import contributes nothing: PHP tokenizes
        // `use Laravel\Ai\Contracts\Tool;` as one qualified-name token, not as `Tool`. What is
        // counted is real code uses of the type — Tool twice (the guard() and bound() parameters),
        // Request zero times, because both its appearances are callable(Request) docblock
        // annotations. That is a sharper measurement than raw text: it counts the thing the
        // exception actually grants.
        'codeUses' => ['Tool' => 2, 'Request' => 0],
    ],
    // Mirrors the seam's two signatures. Inline fully-qualified names in @method docblocks with NO
    // import, so every reference here is a comment: an import-only scan misses this file entirely,
    // and a comments-stripped short-name count sees nothing. The FQCN count is what holds it.
    'src/Facades/Verdict.php' => [
        'fqcns' => ['Laravel\Ai\Contracts\Tool' => 2],
        'codeUses' => [],
    ],
    // A composition root's job is to know both sides: three event classes it registers listeners for.
    'src/VerdictServiceProvider.php' => [
        'fqcns' => [
            'Laravel\Ai\Events\PromptingAgent' => 1,
            'Laravel\Ai\Events\StreamingAgent' => 1,
            'Laravel\Ai\Events\ToolInvoked' => 1,
        ],
        'codeUses' => ['PromptingAgent' => 1, 'StreamingAgent' => 1, 'ToolInvoked' => 1],
    ],
];

/** @return array<string, list<string>> path => the distinct upstream FQCNs it names, outside the zones */
function upstreamSymbolsOutsideZones(): array
{
    $found = [];

    foreach (upstreamReferencesOutsideZones() as $relative => $_) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        preg_match_all('/Laravel\\\\Ai(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $source, $matches);
        $symbols = array_values(array_unique($matches[0]));
        sort($symbols);
        $found[$relative] = $symbols;
    }

    return $found;
}

/**
 * How many times a short class name appears as a whole word in a file's CODE — import line
 * included, comments and docblocks excluded.
 *
 * Comments are stripped deliberately: this file's own explanations name `Tool` and `Request`
 * repeatedly, and a contract that counted prose would turn every clarifying comment into a
 * boundary violation.
 */
function shortNameUses(string $relative, string $shortName): int
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    $uses = 0;

    // IDENTIFIER TOKENS ONLY — not raw text. A text scan counts string literals, so a file could
    // rename its real uses behind an alias and pad the count back with `'Tool'` literals until the
    // arithmetic matched. Counting T_STRING makes the measurement mean "this many times the type
    // is named in code", which is the thing the exception is actually granting.
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === $shortName) {
            $uses++;
        }
    }

    return $uses;
}

/**
 * Upstream imports renamed with `as`, which would let a file use a permitted type under a name the
 * short-name count never sees. Forbidden outright in exception files: an alias buys nothing here
 * and costs the contract its only view of how often the type is named.
 *
 * @return list<string>
 */
function aliasedUpstreamImports(string $relative): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $aliased = [];

    // Token-driven, not a regex over source: whitespace, comments between `use` and `as`, and a
    // leading backslash all defeat a textual pattern while parsing identically. The tokenizer sees
    // through every one of those.
    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_USE) {
            continue;
        }

        for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];

            if (! is_array($next)) {
                break; // reached ; or , — this use statement is finished
            }

            if ($next[0] === T_AS) {
                $imported = $tokens[$j - 1][1] ?? '';

                if (str_starts_with(ltrim((string) $imported, '\\'), 'Laravel\\Ai\\')) {
                    $aliased[] = (string) $imported;
                }

                break;
            }
        }
    }

    return $aliased;
}

/** Exact occurrences of one fully-qualified upstream name, comments included. */
function fqcnUses(string $relative, string $fqcn): int
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);

    return substr_count($source, $fqcn);
}

/** @return array<string, int> path => number of `Laravel\Ai\` occurrences, for files outside the zones */
function upstreamReferencesOutsideZones(): array
{
    $src = realpath(__DIR__.'/../../src');
    $root = str_replace('\\', '/', (string) $src).'/';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $src)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = 'src/'.substr($path, strlen($root));

        foreach (LARAVEL_AI_ZONES as $zone) {
            if (str_starts_with($relative, $zone)) {
                continue 2;
            }
        }

        // Raw source, deliberately: imports, inline fully-qualified references, and docblock types
        // all couple equally, and only the last of those is how the facade names Tool.
        $count = substr_count((string) file_get_contents($file->getPathname()), 'Laravel\\Ai\\');

        if ($count > 0) {
            $found[$relative] = $count;
        }
    }

    ksort($found);

    return $found;
}

it('names upstream types only inside a declared zone or a listed exception', function (): void {
    $expected = array_keys(LARAVEL_AI_EXCEPTIONS);
    sort($expected);
    $actual = array_keys(upstreamReferencesOutsideZones());
    sort($actual);

    expect($actual)->toBe(
        $expected,
        'A file outside src/LaravelAi/ and src/Evaluation/ names a laravel/ai type. Either move the '
        .'reference behind the adapter, or add it to LARAVEL_AI_EXCEPTIONS with a written reason — '
        .'ADR 0033 makes that a deliberate act.',
    );
});

it('holds each exception to its exact symbols and their exact number of uses', function (): void {
    // Member-level, per ADR 0033 §1. A file-level pass would let upstream references accumulate in
    // these three files, which is how a boundary rule dies quietly. Three distinct evasions are
    // caught: swapping a permitted symbol for a different upstream one, adding a bare use of an
    // already-imported symbol, and importing the same symbol again under an alias.
    $symbols = upstreamSymbolsOutsideZones();

    foreach (LARAVEL_AI_EXCEPTIONS as $relative => $rule) {
        $expectedSymbols = array_keys($rule['fqcns']);
        sort($expectedSymbols);

        expect($symbols[$relative] ?? [])->toBe($expectedSymbols, "[{$relative}] names a different set of upstream types.");

        foreach ($rule['fqcns'] as $fqcn => $permitted) {
            expect(fqcnUses($relative, $fqcn))->toBe(
                $permitted,
                "[{$relative}] names [{$fqcn}] a different number of times than the exception permits.",
            );
        }

        expect(aliasedUpstreamImports($relative))->toBe(
            [],
            "[{$relative}] imports an upstream type under an alias, which hides how often it is named.",
        );

        foreach ($rule['codeUses'] as $shortName => $permitted) {
            expect(shortNameUses($relative, $shortName))->toBe(
                $permitted,
                "[{$relative}] uses [{$shortName}] in code a different number of times than the exception permits.",
            );
        }
    }
});

/**
 * What a kernel file may import FROM the adapter namespace, and nothing else.
 *
 * The zone rule alone watches for `Laravel\Ai\` and can be walked around: put
 * `class_alias(\Laravel\Ai\Contracts\Tool::class, __NAMESPACE__.'\Tool')` in a shim inside
 * src/LaravelAi/, import THAT into the kernel, and the kernel has an upstream type back under a
 * local name the scan never sees. Enumerating the permitted kernel -> adapter imports closes it,
 * and is worth having on its own: it is the other half of "the kernel does not depend on the
 * adapter", and it is what forces InvocationContext out rather than merely allowing it out.
 *
 * @var array<string, list<string>>
 */
const KERNEL_ADAPTER_IMPORTS = [
    // The seam's return types (ADR 0033 §4): guard()/bound() construct these and hand them back.
    'src/VerdictManager.php' => ['BoundTool', 'GuardedTool'],
    // The facade mirrors the seam, so it necessarily names the seam's return types in its @method
    // annotations — the same two classes VerdictManager constructs. Omitted from this list at
    // first, which made the contract UNSATISFIABLE: no correct implementation could have gone
    // green, because the only way to clear it would have been to break the documented facade.
    // A rule that cannot be satisfied is worse than no rule; it teaches people to edit the rule.
    'src/Facades/Verdict.php' => ['BoundTool', 'GuardedTool'],
    // The composition root wires the adapter's own services; that is its job.
    'src/VerdictServiceProvider.php' => [
        'PromptProvenanceRegistry',
        'RecordAgentPromptProvenance',
        'RecordToolResultProvenance',
    ],
];

it('lets the kernel import only the enumerated adapter classes', function (): void {
    $src = realpath(__DIR__.'/../../src');
    $root = str_replace('\\', '/', (string) $src).'/';
    $actual = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $src)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = 'src/'.substr($path, strlen($root));

        foreach (LARAVEL_AI_ZONES as $zone) {
            if (str_starts_with($relative, $zone)) {
                continue 2;
            }
        }

        // ANY occurrence of an adapter FQCN, not only the `use Foo\Bar;` form: a grouped import,
        // an aliased one, or an inline `new \Fissible\Verdict\LaravelAi\Thing` are all ordinary
        // PHP that a normal refactor reaches without trying to evade anything. Normalising to the
        // class name and applying the allowlist covers every spelling.
        preg_match_all(
            '/Fissible\\\\Verdict\\\\LaravelAi\\\\([A-Za-z_][A-Za-z0-9_]*)/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        if ($matches[1] !== []) {
            $imported = array_values(array_unique($matches[1]));
            sort($imported);
            $actual[$relative] = $imported;
        }
    }

    ksort($actual);
    $expected = array_map(function (array $names): array {
        sort($names);

        return $names;
    }, KERNEL_ADAPTER_IMPORTS);
    ksort($expected);

    expect($actual)->toBe(
        $expected,
        'A kernel file imports something from the adapter namespace that is not enumerated. '
        .'InvocationContext must move out (ADR 0033 §3), and a new adapter-namespace import needs '
        .'a written reason in KERNEL_ADAPTER_IMPORTS — a class_alias shim would arrive this way.',
    );
});

it('makes adding a zone a deliberate act', function (): void {
    // The rule lives in this file, so nothing can stop someone editing LARAVEL_AI_ZONES — but it
    // can stop them doing it *quietly*. Pinning the list here means a new zone fails until this
    // assertion is edited too, which is where a reviewer is forced to ask why a third part of the
    // package now needs to name upstream types (ADR 0033 Consequences).
    expect(LARAVEL_AI_ZONES)->toBe(['src/LaravelAi/', 'src/Evaluation/']);
});

it('catches an upstream reference that appears only in a docblock', function (): void {
    // The scan's own load-bearing property, asserted rather than assumed. `src/Facades/Verdict.php`
    // has zero `use Laravel\Ai\...` statements — both its references are inline FQCNs inside
    // @method annotations. A scanner built on imports would report this file clean while it names
    // an upstream type twice, and the boundary would be enforced with a hole in it.
    $facade = (string) file_get_contents(__DIR__.'/../../src/Facades/Verdict.php');

    expect($facade)->not->toContain('use Laravel\\Ai')
        ->and(substr_count($facade, 'Laravel\\Ai\\'))->toBe(2)
        ->and(upstreamReferencesOutsideZones())->toHaveKey('src/Facades/Verdict.php');
});

it('keeps the correlation vocabulary out of the adapter namespace', function (): void {
    // ADR 0033 §3: InvocationContext already carries zero upstream references, so it is kernel
    // vocabulary that was merely misfiled. Its address is what made the dependency graph read as
    // kernel -> adapter. This asserts the property, not the destination — which kernel namespace it
    // lands in is the implementer's choice.
    // `class_exists($c, false)` does NOT autoload, so it answers false for a class that exists and
    // has simply not been loaded yet — a false pass that reported this green while the old class
    // was still on disk. Check the file and the autoloadable class.
    expect(file_exists(__DIR__.'/../../src/LaravelAi/InvocationContext.php'))->toBeFalse(
        'src/LaravelAi/InvocationContext.php must not exist: the type is kernel vocabulary.',
    )->and(class_exists('Fissible\\Verdict\\LaravelAi\\InvocationContext'))->toBeFalse(
        'InvocationContext must not be resolvable in the adapter namespace.',
    );

    foreach ([VerdictManager::class, ApprovalManager::class, ContextReleaseManager::class] as $kernelClass) {
        $constructor = (new ReflectionClass($kernelClass))->getConstructor();
        expect($constructor)->not->toBeNull();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            expect($type->getName())->not->toStartWith(
                'Fissible\\Verdict\\LaravelAi\\',
                "{$kernelClass} depends on the adapter namespace through \${$parameter->getName()}.",
            );
        }
    }
});
