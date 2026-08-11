# Streaming Approval Context Lifetime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix issue #22 — `ApprovalExecutionContext`'s "approved tool call IDs" frame is popped the instant `VerdictApprovalMiddleware::handle()`'s `$next($prompt)` call returns, which is correct for synchronous prompts but wrong for streamed ones: a streamed prompt returns a `Laravel\Ai\Responses\StreamableAgentResponse` immediately, wrapping a *lazy* generator that only actually runs (including tool execution) when the response is iterated, later, after the frame is already gone. An already-approved tool call inside a stream therefore fails closed with `ApprovalOutcome::InvalidState`. This is the concrete bug ADR 0006 names as the reason streaming approval resumption doesn't work today.

**Architecture:** `ApprovalExecutionContext` gains `push()`/`pop()` as primitives alongside its existing `within()` (unchanged — `within()` still has real callers outside this fix, see Global Constraints). `VerdictApprovalMiddleware::handle()` uses `push()`/`pop()` directly instead of `within()`, and — only when `$next($prompt)` returns a `StreamableAgentResponse` — defers the `pop()` until the stream is actually consumed, by reconstructing the response with its generator wrapped in `try { ... } finally { pop() }`. The reconstruction reads `StreamableAgentResponse`'s two `protected` constructor properties (`generator`, `meta`) via Reflection, since there's no public accessor for either; both are type-checked after reading, so a future Laravel AI release that renames or restructures either property fails loudly (`ReflectionException` or a explicit `LogicException`) rather than silently misbehaving. No change to `AbstractVerdictTool`, `BoundTool`, `GuardedTool`, or anything downstream of the frame.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4, `laravel/ai` `^0.10.2` (installed `v0.10.3`) — same as the rest of this repo.

## Global Constraints

- PHP `^8.3`, `declare(strict_types=1)` in every modified file.
- 100% type coverage enforced by `composer test` (`pest --type-coverage --min=100`).
- `final`/`final readonly` classes, constructor property promotion, named arguments — match existing style exactly.
- **`ApprovalExecutionContext::within(Decisions, Closure): mixed` must NOT be removed or have its behavior changed.** It has real callers today outside `VerdictApprovalMiddleware`: `workbench/app/Storefront/StorefrontScenarioRunner.php` calls it directly at 6 call sites (lines 161, 165, 170, 633, 684, 699), simulating synchronous approval scenarios without going through the Laravel AI middleware pipeline at all. `push()`/`pop()` are new, additive methods; `within()` may be refactored internally to call them (to avoid duplicating the approved-map-building logic) but its public signature and synchronous push-then-pop-in-finally behavior must be observably identical to today.
- **The reflection reads exactly two properties and nothing else.** `StreamableAgentResponse`'s constructor is `(public string $invocationId, protected Closure $generator, protected ?Meta $meta = null)` — `invocationId` is already public, read it normally (`$response->invocationId`), not via reflection. Only `generator` and `meta` need Reflection.
- **Every value read via Reflection must be type-checked before use**, throwing a `LogicException` with a clear message if the type doesn't match what's expected. This is the load-bearing property that makes this fix's fragility "loud break, not silent misbehavior" — do not skip it to save a few lines.
- No changes to `ApprovalManager`, `AbstractVerdictTool`, `BoundTool`, or `GuardedTool` — the fix is entirely contained to `ApprovalExecutionContext` and `VerdictApprovalMiddleware`.

---

### Task 1: `ApprovalExecutionContext::push()` / `pop()`

**Files:**
- Modify: `src/Approvals/ApprovalExecutionContext.php`
- Test: `tests/Unit/ApprovalExecutionContextTest.php` (new file — no dedicated unit test for this class exists today; it's currently only exercised indirectly through `ApprovalFlowTest.php` and the workbench demo)

**Interfaces:**
- Produces: `ApprovalExecutionContext::push(Decisions $decisions): void` — computes the approved-tool-call-id map from `$decisions` (identical logic to what `within()` already does) and pushes it as a new frame.
- Produces: `ApprovalExecutionContext::pop(): void` — pops the most recent frame.
- Consumes: nothing new. `Laravel\Ai\Approvals\Decisions` is already imported.
- `within()`'s existing signature and behavior are unchanged for every existing caller — refactor its internals to call `push()`/`pop()`, but do not change what it does.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ApprovalExecutionContextTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

it('allows a tool call after push and denies it after pop', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);

    expect($context->allows('call-1'))->toBeFalse();

    $context->push($decisions);

    expect($context->allows('call-1'))->toBeTrue()
        ->and($context->allows('call-unrelated'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-1'))->toBeFalse();
});

it('does not treat a rejected or wildcard decision as an approval', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from([
        'call-rejected' => Decision::reject(),
        '*' => Decision::approve(),
    ]);

    $context->push($decisions);

    expect($context->allows('call-rejected'))->toBeFalse()
        ->and($context->allows('*'))->toBeFalse();

    $context->pop();
});

it('supports nested frames, most recent first', function (): void {
    $context = new ApprovalExecutionContext;

    $context->push(Decisions::from(['call-outer' => Decision::approve()]));
    $context->push(Decisions::from(['call-inner' => Decision::approve()]));

    expect($context->allows('call-inner'))->toBeTrue()
        ->and($context->allows('call-outer'))->toBeFalse();

    $context->pop();

    expect($context->allows('call-outer'))->toBeTrue()
        ->and($context->allows('call-inner'))->toBeFalse();

    $context->pop();
});

it('still supports within() as a synchronous push-then-pop-in-finally convenience', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);
    $seenInsideCallback = null;

    $result = $context->within($decisions, function () use ($context, &$seenInsideCallback): string {
        $seenInsideCallback = $context->allows('call-1');

        return 'callback result';
    });

    expect($seenInsideCallback)->toBeTrue()
        ->and($result)->toBe('callback result')
        ->and($context->allows('call-1'))->toBeFalse();
});

it('pops within()\'s frame even when the callback throws', function (): void {
    $context = new ApprovalExecutionContext;
    $decisions = Decisions::from(['call-1' => Decision::approve()]);

    expect(fn () => $context->within($decisions, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($context->allows('call-1'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Unit/ApprovalExecutionContextTest.php`
Expected: FAIL — `Call to undefined method Fissible\Verdict\Approvals\ApprovalExecutionContext::push()`. The `within()`-only tests (the last two `it(...)` blocks) should already pass against the current, unmodified class — confirm that's the case (2 pass, 3 fail), not all 5 failing, before moving on.

- [ ] **Step 3: Implement**

Replace the full contents of `src/Approvals/ApprovalExecutionContext.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Closure;
use Laravel\Ai\Approvals\Decisions;

final class ApprovalExecutionContext
{
    /** @var list<array<string, true>> */
    private array $frames = [];

    public function allows(string $toolCallId): bool
    {
        $frame = end($this->frames);

        return is_array($frame) && isset($frame[$toolCallId]);
    }

    public function within(Decisions $decisions, Closure $callback): mixed
    {
        $this->push($decisions);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    public function push(Decisions $decisions): void
    {
        $approved = [];

        foreach ($decisions->all() as $toolCallId => $decision) {
            if ($toolCallId !== '*' && $decision->isApproved()) {
                $approved[$toolCallId] = true;
            }
        }

        $this->frames[] = $approved;
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Unit/ApprovalExecutionContextTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the existing indirect coverage to confirm `within()` truly didn't change behavior**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php`
Expected: PASS, same count as before this task (these tests exercise `within()` indirectly through the middleware, which still calls it at this point in the plan — Task 2 is what switches the middleware to `push()`/`pop()`).

- [ ] **Step 6: Commit**

```bash
git add src/Approvals/ApprovalExecutionContext.php tests/Unit/ApprovalExecutionContextTest.php
git commit -m "feat: add push()/pop() primitives to ApprovalExecutionContext"
```

---

### Task 2: Keep the approval frame alive through a streamed response

**Files:**
- Modify: `src/LaravelAi/VerdictApprovalMiddleware.php`
- Modify: `tests/Feature/ApprovalFlowTest.php`

**Interfaces:**
- Consumes: `ApprovalExecutionContext::push()`/`pop()` (Task 1). `Laravel\Ai\Responses\StreamableAgentResponse` (new import — constructor `(string $invocationId, Closure $generator, ?Meta $meta = null)`, `protected Closure $generator`, `protected ?Meta $meta`). `Laravel\Ai\Responses\Data\Meta` (new import, for the type-check only).
- Produces: `VerdictApprovalMiddleware::handle()`'s observable behavior is unchanged for every non-streamed case (identical to today, verified by the existing `ApprovalFlowTest.php` suite passing unmodified). For a streamed `$next($prompt)` result, the approval frame now stays pushed until the returned response is fully iterated, not until `handle()` returns.

This is the core of the fix. Follow strict red-green-commit.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ApprovalFlowTest.php` already has (read them before editing, to place your new code correctly and match exact conventions): `approvalTool()` (a helper building a real `BoundTool` with a capability that `requiresConfirmation`), `approvalPrompt(Decisions $decisions): AgentPrompt`, and `executeWithinApprovalMiddleware(Tool $tool, Request $request, Decisions $decisions): string` (calls `app(VerdictApprovalMiddleware::class)->handle($prompt, fn () => $tool->handle($request))` synchronously). Reuse `approvalTool()` and `approvalPrompt()` as-is.

Add the following imports at the top of the file, alongside the existing ones:

```php
use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
```

Add a new helper function near `executeWithinApprovalMiddleware()` (same file, same conventions):

```php
/**
 * Mirrors executeWithinApprovalMiddleware(), but $next returns a real, lazy
 * StreamableAgentResponse instead of calling $tool->handle($request) synchronously — the
 * tool only actually runs once the returned response is iterated, exactly like a real
 * Laravel AI streamed prompt.
 */
function executeWithinApprovalMiddlewareAsStream(
    Tool $tool,
    Request $request,
    Decisions $decisions,
): StreamableAgentResponse {
    return app(VerdictApprovalMiddleware::class)->handle(
        approvalPrompt($decisions),
        function () use ($tool, $request): StreamableAgentResponse {
            return new StreamableAgentResponse(
                invocationId: 'inv-streamed-approval',
                generator: function () use ($tool, $request): Generator {
                    yield (string) $tool->handle($request);
                },
            );
        },
    );
}
```

Add the new test, placed after the existing `'requires an exact durable approval before executing and consumes it once'` test (same section — this is the streaming counterpart of that exact scenario):

```php
it('keeps an approved tool call approved through a streamed response, not just the middleware\'s synchronous return', function (): void {
    $executions = 0;
    $tool = approvalTool([1001 => new ApprovalOrder(1001, 72)], $executions);
    $request = new Request(['order_id' => 1001], 'call-streamed-cancel');

    $tool->shouldRequestApproval($request);
    $challenge = app(ApprovalManager::class)->challengeForToolCall('call-streamed-cancel');

    expect($challenge)->not->toBeNull();

    // First call: not yet approved, must still come back pending — proves this scenario
    // starts from the same "requires confirmation" state the synchronous tests use.
    $pending = json_decode((string) $tool->handle($request), true, flags: JSON_THROW_ON_ERROR);
    expect($pending['decision'])->toBe('require_confirmation');

    app(ApprovalManager::class)->approve($challenge->receiptId, $challenge->toolCallId, 'customer:72');
    $decisions = Decisions::from(['call-streamed-cancel' => Decision::approve()]);

    $response = executeWithinApprovalMiddlewareAsStream($tool, $request, $decisions);

    // The tool must not have run yet — nothing has iterated $response. If this fails, the
    // test itself is broken (StreamableAgentResponse::getIterator() isn't actually lazy
    // the way this test assumes), not the fix.
    expect($executions)->toBe(0);

    $events = iterator_to_array($response);

    // With the pre-fix code, the approval frame is already popped by the time this
    // iteration runs handle() inside the generator, so the tool sees InvalidState and
    // $executions stays 0. With the fix, the frame is still present.
    expect($executions)->toBe(1)
        ->and($events)->toBe(['cancelled']);
});
```

- [ ] **Step 2: Run to verify it fails, and confirm it fails for the right reason**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php --filter="keeps an approved tool call approved through a streamed response"`
Expected: FAIL. Read the failure — it must be `Failed asserting that 0 matches expected 1` (i.e. `$executions` stayed `0`, meaning the tool saw `InvalidState` and returned pending JSON instead of `'cancelled'`, so `$events` is `['{"status":"not_executed",...}']` not `['cancelled']`), not an error from the test harness itself (e.g. a `TypeError` constructing `StreamableAgentResponse`, or an autoload failure). If it's erroring rather than failing on the assertion, fix the test setup before proceeding — you have not yet reproduced the bug.

- [ ] **Step 3: Implement the fix**

Replace the full contents of `src/LaravelAi/VerdictApprovalMiddleware.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Generator;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use ReflectionClass;
use Throwable;

final readonly class VerdictApprovalMiddleware
{
    public function __construct(private ApprovalExecutionContext $context) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if ($prompt->approvalDecisions === null) {
            return $next($prompt);
        }

        $this->context->push($prompt->approvalDecisions);

        try {
            $response = $next($prompt);
        } catch (Throwable $e) {
            $this->context->pop();

            throw $e;
        }

        if (! $response instanceof StreamableAgentResponse) {
            $this->context->pop();

            return $response;
        }

        return $this->keepApprovalAliveThroughStream($response);
    }

    /**
     * A streamed response is lazy: $next($prompt) returns immediately, well before the
     * underlying generator — and any tool call inside it — actually runs. Popping the
     * approval frame here, the way the synchronous path does, would clear it before
     * ApprovalExecutionContext::allows() is ever consulted, so an already-approved tool
     * call fails closed with ApprovalOutcome::InvalidState. Reconstruct the response so
     * the frame instead pops when the stream is fully consumed, however much later that is
     * — the pop lives inside the generator itself, not in this method's own call stack.
     *
     * StreamableAgentResponse exposes no accessor for its generator or meta, so this reads
     * both protected constructor properties via Reflection and type-checks each value
     * before use. If a future Laravel AI release renames or restructures either property,
     * this throws here — a ReflectionException or the LogicException below — rather than
     * silently misbehaving.
     */
    private function keepApprovalAliveThroughStream(StreamableAgentResponse $response): StreamableAgentResponse
    {
        $reflection = new ReflectionClass(StreamableAgentResponse::class);

        $originalGenerator = $reflection->getProperty('generator')->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $meta = $reflection->getProperty('meta')->getValue($response);

        if ($meta !== null && ! $meta instanceof Meta) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse meta property to be null or a Meta instance.');
        }

        return new StreamableAgentResponse(
            invocationId: $response->invocationId,
            generator: function () use ($originalGenerator): Generator {
                try {
                    yield from call_user_func($originalGenerator);
                } finally {
                    $this->context->pop();
                }
            },
            meta: $meta,
        );
    }
}
```

- [ ] **Step 4: Run to verify the new test passes**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php --filter="keeps an approved tool call approved through a streamed response"`
Expected: PASS.

- [ ] **Step 5: Run the full existing approval suite to confirm no regression on the synchronous path**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php`
Expected: PASS, same count as before plus 1 (the new test). Pay particular attention to `'requires an exact durable approval before executing and consumes it once'` and `'does not accept wildcard or edited Laravel approval decisions'` — these are the tests most likely to reveal a subtle behavioral change if `push()`/`pop()` don't compute the approved-map identically to what `within()` used to do inline.

- [ ] **Step 6: Run static analysis specifically**

Run: `vendor/bin/phpstan analyse src/LaravelAi/VerdictApprovalMiddleware.php --memory-limit=1G`
Expected: `[OK] No errors`. If PHPStan complains about `ReflectionProperty::getValue()`'s `mixed` return type flowing into the `instanceof` checks, that's expected to resolve automatically post-narrowing — but confirm with a real run rather than assuming.

- [ ] **Step 7: Commit**

```bash
git add src/LaravelAi/VerdictApprovalMiddleware.php tests/Feature/ApprovalFlowTest.php
git commit -m "fix: keep the approval frame alive through a streamed response's full iteration"
```

---

### Task 3: Documentation

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/adr/0006-streaming-approval-resumption-deferred.md`
- Modify: `CHANGELOG.md`

**Interfaces:** none — docs only.

- [ ] **Step 1: Update the factual claim in `docs/architecture.md`**

Find, at line 114:

```markdown
This is an early synchronous integration. Streaming approval resumption is not yet supported, because agent middleware returns before a stream is consumed; protected execution fails closed without the scoped approval context. See [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md).
```

Replace with:

```markdown
Streaming approval resumption is supported: `VerdictApprovalMiddleware` keeps the scoped approval context alive for the full duration of a streamed response's iteration, not just until the middleware call returns. See [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md) for why this was a Verdict-side context-lifetime fix rather than a missing Laravel AI capability.
```

- [ ] **Step 2: Add a resolution note to ADR 0006 — do not rewrite its historical decision**

ADRs in this repo are durable decision records, not living status trackers (see the `docs/research-log.md`/issue-workflow convention referenced elsewhere in this repo's docs) — the "Decision" and "Alternatives rejected" sections describe reasoning that's still accurate and should not be rewritten to past tense or otherwise edited. Add a short note instead, immediately under the existing "## Correction" heading's content, as a new final line of that section (do not create a new top-level section for this):

```markdown

**Update:** Issue #22 has landed. `VerdictApprovalMiddleware` now keeps `ApprovalExecutionContext`'s frame alive through a streamed response's full iteration, not just until the middleware call returns synchronously. Streaming approval resumption is supported as of this change.
```

- [ ] **Step 3: Add a `CHANGELOG.md` entry**

Read the file first to match its existing format (flat, verb-led bullets under `## [Unreleased]`, no subsections — confirmed by the absence of any `### ` heading anywhere in the file). Add:

```markdown
- Fix streaming approval resumption: `VerdictApprovalMiddleware` now keeps the scoped
  approval context alive for a streamed response's full iteration instead of popping it
  when the middleware call returns, which happens before a lazy stream is ever consumed.
  An already-approved tool call inside a streamed agent response no longer fails closed
  with `ApprovalOutcome::InvalidState`. See [ADR 0006](docs/adr/0006-streaming-approval-resumption-deferred.md).
```

- [ ] **Step 4: Proofread against the actual shipped behavior**

Re-read `src/LaravelAi/VerdictApprovalMiddleware.php` after Task 2 and confirm every claim in all three doc edits matches it exactly — in particular, that "keeps the frame alive through the full iteration" is still an accurate description and you haven't introduced wording that overclaims (e.g. do not claim this handles every possible failure mode; it does not need to, and the plan does not ask it to — see Task 2's Global Constraints).

- [ ] **Step 5: Commit**

```bash
git add docs/architecture.md docs/adr/0006-streaming-approval-resumption-deferred.md CHANGELOG.md
git commit -m "docs: streaming approval resumption is supported"
```

---

### Task 4: Final verification

**Files:** none — verification only.

- [ ] **Step 1: Full suite, lint, static analysis**

Run: `composer test`
Expected: 0 failures, 100% type coverage, pint clean, phpstan clean.

- [ ] **Step 2: Confirm the diff matches the plan's scope**

Run: `git diff <branch-start-commit> --stat` (the commit this branch forked from — confirm with `git log`/`git merge-base main HEAD` if unsure)
Expected files touched: `src/Approvals/ApprovalExecutionContext.php`, `src/LaravelAi/VerdictApprovalMiddleware.php`, `tests/Unit/ApprovalExecutionContextTest.php`, `tests/Feature/ApprovalFlowTest.php`, `docs/architecture.md`, `docs/adr/0006-streaming-approval-resumption-deferred.md`, `CHANGELOG.md`, plus this plan file under `docs/superpowers/plans/`. Nothing else — in particular, confirm `workbench/app/Storefront/StorefrontScenarioRunner.php` is untouched, proving the `within()` backward-compatibility constraint held.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin fix/streaming-approval-context
gh pr create --repo fissible/verdict --title "fix: keep the approval frame alive through a streamed response" --body "Implements #22."
```

Fill in the PR body using `.github/PULL_REQUEST_TEMPLATE.md`'s sections. "Trust and failure behavior" should state the Reflection-based fragility explicitly and why it's acceptable (loud break via the real, non-mocked streaming test in Task 2, not silent misbehavior) — this is exactly the kind of judgment call worth surfacing to a human reviewer, not hiding. "Verification" should note the new test exercises a real `StreamableAgentResponse` from the installed `laravel/ai` package, not a hand-rolled fake of it.

## Self-Review

**Spec coverage:** every requirement in issue #22's "What's needed" section maps to a task — extending the frame's lifetime across `StreamableAgentResponse` consumption (Task 2), a regression test driving a `Decisions`-carrying prompt through a streamed response and confirming it no longer fails closed with `InvalidState` (Task 2), and the doc/ADR update issue #22 itself says is already in flight (Task 3). The one item in the issue's "What's needed" list not directly addressed — "Verifying this against `StreamedAgentResponse::withPendingApprovals()` / `pausedProviderContentBlocks()` so a stream that pauses again mid-resumption (nested approval) is still handled correctly" — was researched and found to be a non-issue by construction: the fix keeps the frame present for the entire `getIterator()` consumption regardless of how many `ToolApprovalRequest` pause events occur inside a single stream, so multiple pauses within one stream don't need separate handling. This reasoning lives in this plan's research, not restated as a task, since there's no code action it implies.

**Placeholder scan:** no "TBD"/"handle appropriately" strings. Every code block is complete, runnable code, not a sketch.

**Type consistency:** `ApprovalExecutionContext::push(Decisions): void` / `pop(): void` are defined once (Task 1) and consumed identically in Task 2's `VerdictApprovalMiddleware`. `within()`'s signature is verified unchanged and its only caller outside this fix (`StorefrontScenarioRunner.php`) is explicitly called out as a Global Constraint, not discovered mid-task.
