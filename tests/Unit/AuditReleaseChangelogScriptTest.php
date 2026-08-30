<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * #398: nothing checks that an externally-shipped change reached the changelog. The gap during
 * v0.13.0 preparation — 13 of 21 merged changes unrepresented — was found by a person reading a
 * list, which is exactly the check that stops happening the week it matters.
 *
 * The gate is deliberately at RELEASE time, not per PR. Most merged work is internal, and a
 * per-PR requirement would tax every one of them to catch the few that matter; a release is the
 * moment the question actually has to be answered. And the answer it demands is a DECISION, not an
 * entry: an externally-shipped change must either appear in the changelog or be explicitly marked
 * as not needing one. Undecided is the only failing state, because "someone forgot" and "we agreed
 * it does not belong" are indistinguishable from an empty changelog otherwise.
 *
 * WHY THE INPUT IS A FILE. The data all exists already — `gh pr list --json
 * number,title,labels,files,closingIssuesReferences` returns precisely the shape this script
 * reads, so `release.sh` pipes it straight through with no transformation and these tests can
 * exercise the real decision logic without a network. A script that shelled out to `gh` itself
 * would be a script whose behaviour is only observable in production.
 *
 * WHY IT ALSO TAKES THE COMMIT SUBJECTS, which is the part that is easy to get wrong. Everything
 * above reasons about the pull requests it is GIVEN, and would therefore be perfectly satisfied by
 * being given none. `gh pr list` defaults to open pull requests and a limit of 30, so the plausible
 * wiring mistakes all fail open: a wrong state filter, a default limit, a window that is not the
 * tag range. So the release range is not trusted — it is checked. The third input is
 * `git log <last-tag>..HEAD --format=%s`, this repository squash-merges with the pull request
 * number in the subject, and the script requires a record for every number it finds there. A
 * truncated or mis-scoped fetch is then caught by tested code rather than by nobody.
 *
 * WHAT THE GATE DOES NOT COVER, stated because a gate people trust for more than it does is worse
 * than none. It audits PULL-REQUEST-associated changes: the release range is read from commit
 * subjects, so a commit with no trailing merge reference has no pull request to fetch and no file
 * list to classify. Those commits are printed, and that is all — the release is not stopped and no
 * decision is demanded, so the guarantee here is visibility rather than enforcement. On this
 * repository they should not exist, because main is pull-request-only; an admin push bypasses that
 * without saying so, which is precisely why they are printed rather than assumed away. Failing
 * closed on them would need per-commit file lists in the range input, which is a larger change
 * than #398 and one to make on purpose.
 *
 * The mirror of that blind spot is worth naming too, because the visibility guarantee does not
 * reach it: a direct commit whose subject happens to END in `(#402)` is indistinguishable from a
 * squash merge, so it is attributed to whatever pull request #402 turns out to be — and if that
 * one is decided, the direct commit rides along silently. Nothing here detects it. Commit subjects
 * are a convention, not a signature; treating them as authorship is the price of reading the range
 * from data that already exists rather than from a per-commit fetch.
 *
 * WHAT THE RELEASE-SCRIPT CONFORMANCE TESTS CANNOT SEE, stated so nobody mistakes them for more.
 * They read `release.sh` as text: they prove the shape and the ORDER of its statements, not that
 * any of them execute. A matching line inside a heredoc, an uncalled function or an unreachable
 * branch would satisfy them. Verifying execution would mean running a release, which is not a
 * thing a test suite can do, and short of that a bash parser is more machinery than the risk earns
 * — the same boundary `MysqlSmokeLaneConformanceTest` accepts for the workflow.
 *
 * Two of those escapes are closed cheaply: the invocation must sit at column zero, and this script
 * indents everything inside a function or a conditional, so an uncalled helper and an `if` body are
 * both rejected. A heredoc is not closed, and neither is a top-level branch that returns early
 * above it. So the honest claim is narrower than "the gate runs": a future edit cannot DELETE the
 * gate, move it after promotion, or append a status-swallowing continuation to it without a red
 * test. Whether the line is reached on every path is not something these tests establish.
 *
 * WHAT "EXTERNALLY SHIPPED" MEANS HERE, and its known blind spot. A change counts when it touches
 * `src/`, `config/` or `database/` — the consumer-facing behaviour surface. It is tempting to
 * derive this from `.gitattributes` instead, since that names what the package actually ships, but
 * that set is wrong in the other direction: `.github/` and `release.sh` are in the distributed
 * tarball and change nothing a consumer can observe, so a CI-only PR would be flagged forever. The
 * cost of the narrower rule is that a docs-only change with real external meaning is not demanded;
 * the explicit-decision escape exists for everything this rule misclassifies, in both directions.
 */
function verdictAuditFixture(string $changelog, string $pullRequests, ?string $subjects = null): array
{
    $directory = sys_get_temp_dir().'/verdict-audit-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create the audit-test directory.');
    }

    file_put_contents($directory.'/CHANGELOG.md', $changelog);
    file_put_contents($directory.'/prs.json', $pullRequests);
    file_put_contents($directory.'/subjects.txt', $subjects ?? verdictAuditSubjectsFor($pullRequests));

    return [$directory, $directory.'/CHANGELOG.md', $directory.'/prs.json', $directory.'/subjects.txt'];
}

/**
 * Commit subjects consistent with the supplied pull requests, so a test that is not about the
 * range boundary does not have to restate it. Squash-merge shape: the number in trailing parens.
 */
function verdictAuditSubjectsFor(string $pullRequests): string
{
    $decoded = json_decode($pullRequests, true);

    if (! is_array($decoded)) {
        return '';
    }

    $lines = [];

    foreach ($decoded as $pullRequest) {
        if (is_array($pullRequest) && isset($pullRequest['number'])) {
            $lines[] = "fix: something (#{$pullRequest['number']})";
        }
    }

    return implode("\n", $lines)."\n";
}

function removeVerdictAuditFixture(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($directory);
}

function runVerdictAudit(string $changelogPath, string $pullRequestsPath, string $subjectsPath): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/scripts/audit-release-changelog.php',
        $changelogPath,
        $pullRequestsPath,
        $subjectsPath,
    ]);
    $process->run();

    return $process;
}

/** A changelog whose Unreleased section cites the given references, in this repository's style. */
function verdictAuditChangelog(string $unreleased, string $released = '- **Something older (#12).** Shipped.'): string
{
    return <<<MARKDOWN
    # Changelog

    All notable changes to Verdict will be documented in this file.

    ## [Unreleased]

    ### Fixed

    {$unreleased}

    ## [0.13.0] - 2026-08-29

    ### Fixed

    {$released}

    MARKDOWN;
}

/** @param list<array<string, mixed>> $pullRequests */
function verdictAuditPullRequests(array $pullRequests): string
{
    return json_encode($pullRequests, JSON_THROW_ON_ERROR);
}

/**
 * One merged pull request in the shape `gh pr list --json` returns it.
 *
 * @param  list<string>  $files
 * @param  list<string>  $labels
 * @param  list<int>  $closes
 * @return array<string, mixed>
 */
function verdictAuditPullRequest(int $number, array $files, array $labels = [], array $closes = [], string $title = 'a change'): array
{
    return [
        'number' => $number,
        'title' => $title,
        'labels' => array_map(fn (string $label): array => ['name' => $label], $labels),
        'files' => array_map(fn (string $path): array => ['path' => $path], $files),
        'closingIssuesReferences' => array_map(fn (int $issue): array => ['number' => $issue], $closes),
    ];
}

it('passes when every externally-shipped change is represented', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Evidence/DatabaseEvidenceRecorder.php'], closes: [391]),
        ]),
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // The positive control. Without it an implementation that failed unconditionally would satisfy
    // every failing case below and stop every release.
    expect($process->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('fails naming an externally-shipped change with no entry and no decision', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Evidence/DatabaseEvidenceRecorder.php'], closes: [391], title: 'evidence degradation'),
        ]),
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // The whole point. Naming the pull request and its title is what makes the failure actionable
    // at 11pm on a release evening rather than a puzzle to re-derive.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('402')
        ->and($process->getErrorOutput())->toContain('evidence degradation');

    removeVerdictAuditFixture($directory);
});

it('accepts a changelog entry that cites the issue the pull request closes', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        // This repository cites the ISSUE in changelog entries — "(#391)" — while the merge commit
        // carries the pull request number. A gate that only looked for the pull request number
        // would fail every correctly-written entry in this file's history.
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Verdict.php'], closes: [391]),
        ]),
    );

    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('accepts a changelog entry that cites the pull request itself', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#402).** Details.'),
        // No closing issue: plenty of merged work has none, and citing the pull request is the only
        // reference such an entry can carry.
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Verdict.php']),
        ]),
    );

    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('accepts an externally-shipped change explicitly marked as needing no entry', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Verdict.php'], labels: ['release: no changelog']),
        ]),
    );

    // Decided, not forgotten. The gate exists to force the question, not to force an entry — a
    // rename with no observable effect is a legitimate answer, provided somebody gave it.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('ignores a change that ships nothing a consumer can observe', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(406, [
                '.github/workflows/tests.yml',
                'tests/Unit/MysqlSmokeLaneConformanceTest.php',
                'CHANGELOG.md',
            ]),
        ]),
    );

    // The noise floor, and the reason this is a release gate rather than a per-PR one: most merged
    // work looks like this. A gate that flagged it would be turned off within a release or two.
    // These are the real paths from #406.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('counts a configuration or migration change as externally shipped', function (array $files): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([verdictAuditPullRequest(402, $files)]),
    );

    // Published config keys and migration stubs are consumer-facing surface as much as src/ is: an
    // adopter re-publishes them by hand. Restricting the rule to src/ would have missed #290's
    // table-name change entirely.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(1);

    removeVerdictAuditFixture($directory);
})->with([
    'config' => [['config/verdict.php']],
    'migration' => [['database/migrations/create_verdict_evidence_table.php.stub']],
]);

it('does not accept an entry that appears only in an already-released section', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog(
            '- **Something unrelated (#111).** Details.',
            released: '- **A guard changed (#391).** Shipped in the last release.',
        ),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/Verdict.php'], closes: [391]),
        ]),
    );

    // Only the Unreleased section is the release being prepared. A reference in released history is
    // a different change that happened to reuse the number, or the same one already shipped —
    // either way it is not evidence about this release, and matching it would let a whole release
    // pass by coincidence.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(1);

    removeVerdictAuditFixture($directory);
});

it('names every undecided change rather than the first', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(401, ['src/A.php'], title: 'first change'),
            verdictAuditPullRequest(402, ['src/B.php'], title: 'second change'),
            verdictAuditPullRequest(403, ['src/C.php'], title: 'third change'),
        ]),
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);
    $error = $process->getErrorOutput();

    // The v0.13.0 gap was 13 changes, not one. A gate that reported them one per release run would
    // take thirteen runs to clear, which is a gate nobody would keep.
    expect($process->getExitCode())->toBe(1)
        ->and($error)->toContain('401')
        ->and($error)->toContain('402')
        ->and($error)->toContain('403');

    removeVerdictAuditFixture($directory);
});

it('passes when nothing has merged since the last tag', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([]),
    );

    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('fails loudly rather than passing when its input cannot be read', function (string $changelogSuffix, string $pullRequestsBody): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        $pullRequestsBody,
    );

    $process = runVerdictAudit($changelog.$changelogSuffix, $pullRequests, $subjects);

    // A release gate that cannot read its inputs must stop the release, not wave it through. This
    // is the failure mode that makes a gate worse than none: silence that looks like approval.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->not->toBe('');

    removeVerdictAuditFixture($directory);
})->with([
    'missing changelog' => ['.absent', '[]'],
    'malformed pull-request json' => ['', 'not json at all'],
    'pull-request json that is not a list' => ['', '{"number": 402}'],
]);

it('fails when the release range names a pull request the fetch did not return', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        // The fetch returned one pull request; the release range contains two.
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
        subjects: "fix: one (#402)\nfix: two (#407)\n",
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // The failure mode every other test in this file is blind to. `gh pr list` defaults to OPEN
    // pull requests and a limit of 30, so the realistic wiring mistakes — wrong state, default
    // limit, wrong window — all return too few records, and a gate that only reasons about what it
    // was handed approves the release enthusiastically. The range is checked, not trusted.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('407');

    removeVerdictAuditFixture($directory);
});

it('ignores a pull request the release range does not contain', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            // In range and decided.
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
            // Externally shipped and undecided — but merged before the last tag, so not this
            // release's problem.
            verdictAuditPullRequest(300, ['src/Old.php']),
        ]),
        subjects: "fix: one (#402)\n",
    );

    // Over-fetching must not manufacture failures, or the gate becomes unusable the first time
    // someone widens the query — and a widened query is the obvious response to the range check
    // firing. Exit zero with an undecided pull request sitting in the input is the whole claim.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('reads the release range exactly, not as a substring', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(391, ['src/A.php']),
        ]),
        subjects: "fix: a different change (#3910)\n",
    );

    // The same exactness the changelog matching needs, on the other input. #3910 is not #391, so
    // the fetch does not cover this range and the release must stop.
    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('3910');

    removeVerdictAuditFixture($directory);
});

it('matches a changelog reference exactly rather than as a substring', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        // #3910 contains "#391". A naive str_contains would call this represented.
        verdictAuditChangelog('- **A different change (#3910).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
    );

    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(1);

    removeVerdictAuditFixture($directory);
});

it('does not treat a near-miss label as an explicit decision', function (string $label): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], labels: [$label]),
        ]),
    );

    // The waiver is the one way to bypass the gate, so it has to be the label somebody deliberately
    // created and applied — not anything that reads a bit like it.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(1);

    removeVerdictAuditFixture($directory);
})->with([
    'prefix' => ['release: no changelog needed'],
    'different scope' => ['docs: no changelog'],
    'bare' => ['no changelog'],
]);

it('does not count a path that merely resembles a shipped directory', function (array $files): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        verdictAuditPullRequests([verdictAuditPullRequest(402, $files)]),
    );

    // Directory boundaries, not string prefixes. `src-old/` is not `src/`, and a PHP file under
    // `docs/` is documentation whatever it is called — a gate that flagged either would train
    // people to reach for the waiver, which is how a gate stops meaning anything.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
})->with([
    'a sibling directory' => [['src-old/Foo.php']],
    'a php file under docs' => [['docs/src/example.php']],
    'a config-like name outside config' => [['workbench/config/verdict.php']],
]);

it('fails closed on a pull-request record it cannot understand', function (string $record): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        '['.$record.']',
        subjects: "fix: something (#402)\n",
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // A record with a missing or mistyped field must stop the release, not be skipped. Skipping is
    // indistinguishable from "this change ships nothing", which is precisely the conclusion the
    // gate exists to stop anyone reaching by accident.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->not->toBe('');

    removeVerdictAuditFixture($directory);
})->with([
    'no number' => ['{"title":"x","labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'number is not an integer' => ['{"number":"402","title":"x","labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'files is not a list' => ['{"number":402,"title":"x","labels":[],"files":"src/A.php","closingIssuesReferences":[]}'],
    'a file entry has no path' => ['{"number":402,"title":"x","labels":[],"files":[{"additions":3}],"closingIssuesReferences":[]}'],
    'labels is not a list' => ['{"number":402,"title":"x","labels":"bug","files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'a label entry has no name' => ['{"number":402,"title":"x","labels":[{"color":"red"}],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'closing issues is not a list' => ['{"number":402,"title":"x","labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":391}'],
    'a closing issue has no number' => ['{"number":402,"title":"x","labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[{"url":"x"}]}'],
    'no title' => ['{"number":402,"labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'the record is not an object' => ['402'],
    'title is not a string' => ['{"number":402,"title":7,"labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'a file path is not a string' => ['{"number":402,"title":"x","labels":[],"files":[{"path":7}],"closingIssuesReferences":[]}'],
    'a label name is not a string' => ['{"number":402,"title":"x","labels":[{"name":7}],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[]}'],
    'a closing issue number is not an integer' => ['{"number":402,"title":"x","labels":[],"files":[{"path":"src/A.php"}],"closingIssuesReferences":[{"number":"391"}]}'],
]);

/**
 * Deliberate and worth stating: one changelog entry citing an issue clears EVERY pull request that
 * closes it. #251 shipped across five, and demanding five citations for one narrative would push
 * writers toward five thin entries instead of one true one. The gate asks whether the change was
 * decided about, not whether the prose enumerates its merges.
 */
it('accepts one entry covering several pull requests that close the same issue', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A filtered permit (#251).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(263, ['src/A.php'], closes: [251]),
            verdictAuditPullRequest(264, ['src/B.php'], closes: [251]),
        ]),
    );

    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('reads the pull request from a subject that also cites its issue', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A lane was added (#397).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(406, ['src/A.php'], closes: [397]),
        ]),
        // The real shape of this repository's history: `git log --oneline` on main reads
        // "ci: a required per-PR MySQL lane ... (#397) (#406)". The issue comes first because the
        // branch commit named it; the squash merge appends the pull request.
        subjects: "ci: a required per-PR MySQL lane over a bounded, maintained slice (#397) (#406)\n",
    );

    // The trailing number is the pull request, and only it. Treating BOTH as pull requests would
    // demand a fetched record for #397 — an issue, which `gh pr list` will never return — and
    // every correctly-formed release would fail. The two-number subject is the common case here,
    // not an edge one.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('requires the trailing pull request of a two-number subject', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A lane was added (#397).** Details.'),
        // The fetch returned the ISSUE number as though it were the pull request. A parser that
        // took the first number would be satisfied; the release range is genuinely uncovered.
        verdictAuditPullRequests([
            verdictAuditPullRequest(397, ['src/A.php']),
        ]),
        subjects: "ci: a required per-PR MySQL lane (#397) (#406)\n",
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('406');

    removeVerdictAuditFixture($directory);
});

it('reports an in-range commit it cannot attribute to a pull request', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
        // Not every subject in a range ends in a merge reference — a revert, a hand-made commit,
        // anything the parser cannot read a trailing number from. It must not become a demand for
        // a pull request that does not exist, and it must not vanish either. (The previous release
        // commit is NOT such a case: the tag points at it, so `last_tag..HEAD` excludes it, and
        // this audit runs before this release's own commit is made.)
        subjects: "fix: one (#402)\nrevert an experiment\n",
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // Reported, and NOT blocked — the weaker of the two outcomes, said plainly. This is the gate's
    // real blind spot: main is pull-request-only by branch protection, yet an admin push bypasses
    // that quietly, so a hand-made commit touching src/ would ship with nothing to audit it. The
    // gate has subjects, not file lists, for such commits, so it cannot tell a revert from a src/
    // change and will not fail a release over something it cannot classify. What it can do is
    // refuse to be silent, which is what this asserts. Making it fail closed would need per-commit
    // file lists in the range input — a bigger change than #398, and one to make deliberately.
    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('revert an experiment');

    removeVerdictAuditFixture($directory);
});

it('fails when the fetch returns the same pull request twice', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **Something unrelated (#111).** Details.'),
        // Same number twice: one record shipping src/ and undecided, one waived and shipping
        // nothing. An implementation that indexes by number lets the second overwrite the first
        // and reports a clean release.
        '['.json_encode(verdictAuditPullRequest(402, ['src/A.php']), JSON_THROW_ON_ERROR).','
            .json_encode(verdictAuditPullRequest(402, ['README.md'], labels: ['release: no changelog']), JSON_THROW_ON_ERROR).']',
        subjects: "fix: one (#402)\n",
    );

    // Ambiguous input is not a licence to pick one. Two records for one pull request means the
    // fetch is not describing the release, and guessing which is authoritative is exactly the
    // quiet wrong answer this gate exists to prevent.
    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('402');

    removeVerdictAuditFixture($directory);
});

it('fails on a duplicated pull request even when every copy is decided', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        // Both copies would pass on their own. The only thing wrong here is that there are two.
        '['.json_encode(verdictAuditPullRequest(402, ['src/A.php'], closes: [391]), JSON_THROW_ON_ERROR).','
            .json_encode(verdictAuditPullRequest(402, ['src/A.php'], closes: [391]), JSON_THROW_ON_ERROR).']',
        subjects: "fix: one (#402)\n",
    );

    // Isolates duplicate DETECTION from undecidedness. Without this, an implementation that walked
    // the records independently and happened to fail on an undecided one would satisfy the test
    // above while never noticing the duplication at all.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(1);

    removeVerdictAuditFixture($directory);
});

it('reads a reference only where a squash merge puts it', function (): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
        // A subject that happens to mention an issue mid-sentence. A squash merge always appends
        // its reference at the end, so only a terminal one is evidence that a pull request merged.
        subjects: "fix: one (#402)\nfix: mention (#397) in the docs\n",
    );

    // Reading #397 as a merged pull request would demand a fetched record for it and fail a
    // release that is completely in order.
    expect(runVerdictAudit($changelog, $pullRequests, $subjects)->getExitCode())->toBe(0);

    removeVerdictAuditFixture($directory);
});

it('fails when the changelog has no Unreleased section to read', function (string $changelogBody): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        $changelogBody,
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
    );

    $process = runVerdictAudit($changelog, $pullRequests, $subjects);

    // An unparseable Unreleased section reads as an empty one, and an empty one makes every change
    // undecided — which sounds safe until you notice the other direction: a section this script
    // cannot find is one it cannot search, and reporting "all clear" over a changelog it never
    // read is the failure that makes a gate worse than none.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->not->toBe('');

    removeVerdictAuditFixture($directory);
})->with([
    'no unreleased heading' => ["# Changelog\n\n## [0.13.0] - 2026-08-29\n\n- **A guard changed (#391).** Details.\n"],
    'unreleased with no release section after it' => ["# Changelog\n\n## [Unreleased]\n\n- **A guard changed (#391).** Details.\n"],
    'two unreleased headings' => ["# Changelog\n\n## [Unreleased]\n\n## [Unreleased]\n\n- **A guard changed (#391).** Details.\n\n## [0.13.0] - 2026-08-29\n\n- old\n"],
]);

it('fails when the release range input cannot be read', function (string $mode): void {
    [$directory, $changelog, $pullRequests, $subjects] = verdictAuditFixture(
        verdictAuditChangelog('- **A guard changed (#391).** Details.'),
        verdictAuditPullRequests([
            verdictAuditPullRequest(402, ['src/A.php'], closes: [391]),
        ]),
    );

    // Absent and unreadable are different failures — one is a missing file, the other a path that
    // exists and cannot be read as a file — and a naive implementation catches only the first.
    // Either would otherwise read as "no commits in this release", which passes every check while
    // auditing nothing at all.
    $path = $mode === 'absent' ? $subjects.'.absent' : $directory;

    $process = runVerdictAudit($changelog, $pullRequests, $path);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->not->toBe('');

    removeVerdictAuditFixture($directory);
})->with(['absent', 'a directory']);

/**
 * The gate only exists if the release actually runs it. These read `release.sh` as text, the way
 * `MysqlSmokeLaneConformanceTest` reads the workflow: a script whose logic is perfect and whose
 * caller never invokes it is a gate in name only, and that is not a distinction a unit test of the
 * script itself can make.
 */
function verdictReleaseScript(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/release.sh');
}

it('preflights the audit script and its dependency the way it preflights the others', function (): void {
    $script = verdictReleaseScript();

    // Same shape as the existing guard for prepare-release-changelog.php: an absent gate must stop
    // the release with a reason, not be skipped because a file is missing. `gh` earns a guard of
    // its own because it is a NEW dependency of this script — the existing preflight names php and
    // git only, and a release run on a machine without `gh` would otherwise fail somewhere less
    // legible than the preflight block.
    // Each guard must ACT on its condition. `[[ -f x ]] || :` matches a condition-shaped line and
    // ordering while neutralising the failure under `set -e`; `|| die` is what actually stops the
    // release, and it is the shape every existing preflight in this script already uses.
    [$fileGuard] = verdictAuditStatement('/^\[\[ -f scripts\/audit-release-changelog\.php \]\]/');
    [$ghGuard] = verdictAuditStatement('/^command -v gh\b/');

    expect($fileGuard)->toContain('|| die')
        ->and($ghGuard)->toContain('|| die');

    // And as executable statements in the preflight block, before anything is changed. A guard
    // that fires after the version has been bumped and the changelog rewritten leaves the tree
    // half-released, which is a worse place to stop than not starting — and a guard that is only a
    // comment reassures a reader while stopping nothing.
    $guard = verdictAuditExecutableLine('/^\[\[ -f scripts\/audit-release-changelog\.php \]\]/');
    $gh = verdictAuditExecutableLine('/^command -v gh\b/');
    $audit = verdictAuditExecutableLine(VERDICT_AUDIT_INVOCATION);
    $script = verdictReleaseScript();
    // The first thing the release actually writes.
    $mutation = verdictAuditExecutableLine('/> VERSION$/');

    expect($guard)->toBeLessThan($audit)
        ->and($gh)->toBeLessThan($audit)
        ->and($guard)->toBeLessThan($mutation);
});

it('runs the audit before promoting the Unreleased section', function (): void {
    // Executable lines on both sides. A commented audit invocation sitting above the promotion
    // would satisfy a raw text search while the real one ran after it — which is precisely the
    // arrangement this test exists to forbid.
    $audit = verdictAuditExecutableLine(VERDICT_AUDIT_INVOCATION);
    $promotions = [];

    foreach (explode("\n", verdictReleaseScript()) as $number => $line) {
        if (preg_match('/^php scripts\/prepare-release-changelog\.php\b/', $line) === 1) {
            $promotions[] = $number;
        }
    }

    // Every promotion, not the first one found: a decoy above the audit would otherwise satisfy the
    // ordering while the real promotion ran before it.
    expect($promotions)->not->toBeEmpty();

    $promote = min($promotions);

    // Order is the whole contract. Promotion empties the Unreleased section into a numbered
    // release; auditing afterwards would ask its question of a section that no longer exists and
    // pass every time.
    expect($audit)->toBeLessThan($promote);
});

/** The audit invocation line, found once and reasoned about as an executable statement. */
function verdictAuditInvocation(): string
{
    // The JOINED statement, not the first physical line: `php scripts/audit-... \` followed by
    // `|| true` would otherwise pass every status-neutralisation check below by hiding the
    // continuation on the next line.
    [$statement] = verdictAuditStatement(VERDICT_AUDIT_INVOCATION);

    $lines = [];

    foreach (explode("\n", verdictReleaseScript()) as $line) {
        // Unindented, and that carries weight rather than being tidiness: `release.sh` indents
        // everything inside a function or a conditional, so requiring column zero rejects the two
        // ways an otherwise-clean invocation can sit somewhere it never runs — an uncalled helper
        // and an `if` body. A one-line `if php scripts/... ; then` is rejected too, because the
        // line would then start with `if`.
        if (str_starts_with($line, 'php scripts/audit-release-changelog.php')) {
            $lines[] = $line;
        }
    }

    expect($lines)->toHaveCount(1, 'exactly one top-level executable audit invocation');

    return $statement;
}

it('lets the audit stop the release rather than reporting and continuing', function (): void {
    $script = verdictReleaseScript();

    // `set -e` is what makes a non-zero exit abort, so the invocation must not be neutralised.
    // A gate whose failure is swallowed is worse than no gate: it produces a reassuring line of
    // output and ships anyway. Backgrounding is the same failure wearing different syntax — the
    // release would race past it and never see the exit code at all.
    expect($script)->toMatch('/^set -e/m');

    $invocation = verdictAuditInvocation();

    // One standalone command, nothing appended. Every one of these neutralises a failing audit
    // while leaving the invocation visible and reassuring: `|| true` and `&& next` swallow the
    // status, `; :` replaces it with the status of a no-op, a pipeline reports the LAST command's
    // status unless pipefail is set, and a trailing `&` means the release never waits to find out.
    expect($invocation)->not->toContain('||')
        ->and($invocation)->not->toContain('&&')
        ->and($invocation)->not->toContain(';')
        ->and($invocation)->not->toContain('|')
        ->and(rtrim($invocation))->not->toEndWith('&')
        ->and($script)->not->toMatch('/^\s*set \+e/m');
});

it('hands the audit all three of its inputs', function (): void {
    // The script reads a changelog, a pull-request fetch and the release range. Passing two of the
    // three would make it exit on its usage check — non-zero, so `set -e` would stop the release,
    // and the gate would look like it worked while never having run.
    expect(verdictAuditInvocation())->toMatch('/audit-release-changelog\.php\s+\S+\s+\S+\s+\S+/');
});

/**
 * The executable line producing one of the audit's data inputs, with the shell variable it writes
 * to. Matching on the redirect target is what turns separate text assertions into a dataflow
 * claim: it is not enough that the script mentions `gh pr list` somewhere and passes something to
 * the audit — what it passes has to be what that command produced.
 *
 * This prescribes a shape (fetch into a variable-named file, then pass that variable), and
 * deliberately: it is the only shape a text conformance test can verify, and it is the one the
 * wiring would take anyway.
 *
 * @return array{string, string}
 */
/**
 * The variable as a whole shell argument, not as a substring: `"$pr"` must not be satisfied by
 * `"$prs"`, which is exactly the kind of near-miss a rename leaves behind.
 */
function verdictAuditArgumentPattern(string $variable): string
{
    return '/"\\$\\{?'.preg_quote($variable, '/').'\\}?"/';
}

/**
 * The line number of the first EXECUTABLE line whose shape matches $pattern.
 *
 * Comments are skipped, because a commented guard reassures a reader and stops nothing. The
 * pattern is anchored by its callers rather than being a substring search, because an executable
 * line can still contain a guard without performing one — `printf '%s\n' 'command -v gh'` is a
 * line of output, not a check.
 *
 * Column zero, for the same reason {@see verdictAuditInvocation()} requires it, and symmetrically:
 * every anchor these ordering assertions compare against has to be as hard to fake as the audit
 * invocation itself. An indented decoy `php scripts/prepare-release-changelog.php` inside a helper
 * would otherwise be matched first and satisfy "the audit runs before promotion" while the real
 * promotion ran earlier.
 */
function verdictAuditExecutableLine(string $pattern): int
{
    return verdictAuditStatement($pattern)[1];
}

/**
 * The top-level statement matching $pattern, joined across backslash continuations, with the line
 * it starts on.
 *
 * Joining matters for the guards: this script writes them as `[[ -f x ]] \` on one line and
 * `|| die "..."` on the next, so a test that read single lines could see the condition and never
 * see whether anything acts on it.
 *
 * @return array{string, int}
 */
function verdictAuditStatement(string $pattern): array
{
    $lines = explode("\n", verdictReleaseScript());
    $count = count($lines);

    for ($number = 0; $number < $count; $number++) {
        $line = $lines[$number];

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            continue;
        }

        $statement = $line;
        $cursor = $number;

        while (str_ends_with(rtrim($statement), '\\') && $cursor + 1 < $count) {
            $cursor++;
            $statement = rtrim(rtrim($statement), '\\').' '.ltrim($lines[$cursor]);
        }

        if (preg_match($pattern, $statement) === 1) {
            return [$statement, $number];
        }
    }

    throw new RuntimeException("No top-level executable statement matches [{$pattern}].");
}

/** @var non-empty-string */
const VERDICT_AUDIT_INVOCATION = '/^php scripts\/audit-release-changelog\.php\b/';

/**
 * A shell line with any trailing comment removed — a `#` outside quotes and everything after it.
 *
 * Central, so no assertion in this file can be satisfied by commentary. That is not hypothetical:
 * the first implementation of the empty-tag-safe `git log` range satisfied this file's literal
 * range expectation only through a trailing comment, which is the same failure the preflight and
 * invocation checks already close.
 */
function verdictAuditWithoutComment(string $line): string
{
    $single = false;
    $double = false;

    for ($index = 0, $length = strlen($line); $index < $length; $index++) {
        $character = $line[$index];

        if ($character === "'" && ! $double) {
            $single = ! $single;
        } elseif ($character === '"' && ! $single) {
            $double = ! $double;
        } elseif ($character === '#' && ! $single && ! $double) {
            return rtrim(substr($line, 0, $index));
        }
    }

    return rtrim($line);
}

/** @return array{string, string, int} */
function verdictAuditProducer(string $command): array
{
    $found = [];

    foreach (explode("\n", verdictReleaseScript()) as $number => $line) {
        if (str_starts_with($line, $command)) {
            $found[] = $number;
        }
    }

    // Exactly one, so "the producer runs before the audit" cannot be satisfied by a decoy sitting
    // above it while the real one runs later.
    expect($found)->toHaveCount(1, "exactly one top-level [{$command}] statement");

    foreach (explode("\n", verdictReleaseScript()) as $number => $line) {
        // Column zero, for the same reason the invocation requires it: an indented producer inside
        // a helper could otherwise be matched first and satisfy "it runs before the audit" while
        // the real one ran later.
        if (! str_starts_with($line, $command)) {
            continue;
        }

        $statement = verdictAuditWithoutComment($line);

        if (preg_match('/>\s*"\$\{?(?<variable>\w+)\}?"/', $statement, $matches) === 1) {
            return [$statement, $matches['variable'], $number];
        }
    }

    throw new RuntimeException("No top-level [{$command}] line redirecting into a shell variable.");
}

it('fetches the pull requests the release actually contains', function (): void {
    [$line, $variable, $at] = verdictAuditProducer('gh pr list');

    // The two ways this fetch fails open, both of them the CLI's own defaults: `gh pr list`
    // returns OPEN pull requests unless told otherwise, and caps at 30 unless given a limit. And
    // the audit has to be handed THIS file — a fetch whose output goes nowhere is not a fetch.
    expect($line)->toContain('--json')
        ->and($line)->toContain('--state merged')
        ->and(verdictAuditInvocation())->toMatch(verdictAuditArgumentPattern($variable))
        // And it has to run BEFORE the audit. A producer placed after it would leave the audit
        // reading whatever the file held already — an empty temp file, or last release's fetch.
        ->and($at)->toBeLessThan(verdictAuditExecutableLine(VERDICT_AUDIT_INVOCATION));

    // A limit of 1 satisfies "has a limit" and silently truncates every ordinary release. The
    // range check would catch it, but at release time and as a puzzle; pinning a limit that
    // comfortably exceeds any real release makes it a red test on a pull request instead.
    preg_match('/--limit\s+(?<limit>\d+)/', $line, $limit);

    expect($limit)->toHaveKey('limit')
        ->and((int) $limit['limit'])->toBeGreaterThanOrEqual(200);
});

it('derives the release range from the commits since the last tag', function (): void {
    [$line, $variable, $at] = verdictAuditProducer('git log');

    // The third input, and what the range check depends on. Reading a wider or narrower window
    // here silently changes which pull requests the release believes it contains.
    // Either the plain tagged range or the empty-tag-safe expansion, which resolves to bare HEAD
    // when there is no previous tag — the state the commit survey above already handles and the
    // one a repository's first release is in. A hard-coded tag or a different endpoint fails.
    expect($line)->toMatch('/(?:\$last_tag\.\.HEAD|\$\{last_tag:\+\$last_tag\.\.\}HEAD)/')
        ->and($line)->toContain('--format=%s')
        ->and(verdictAuditInvocation())->toMatch(verdictAuditArgumentPattern($variable))
        ->and($at)->toBeLessThan(verdictAuditExecutableLine(VERDICT_AUDIT_INVOCATION));
});

it('asks for every field the audit reads', function (): void {
    [$line] = verdictAuditProducer('gh pr list');

    // A fetch missing `files` classifies every change as shipping nothing; one missing `labels`
    // ignores every waiver. Both fail in the quiet direction, so the field list is pinned — and on
    // the fetch line itself, so a mention elsewhere in the script cannot satisfy it.
    foreach (['number', 'title', 'labels', 'files', 'closingIssuesReferences'] as $field) {
        expect($line)->toContain($field);
    }
});
