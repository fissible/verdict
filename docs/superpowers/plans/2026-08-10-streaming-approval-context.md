# Streaming Approval Context Lifetime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix issue #22 — `ApprovalExecutionContext`'s "approved tool call IDs" frame is popped the instant `VerdictApprovalMiddleware::handle()`'s `$next($prompt)` call returns, which is correct for synchronous prompts but wrong for streamed ones: a streamed prompt returns a `Laravel\Ai\Responses\StreamableAgentResponse` immediately, wrapping a *lazy* generator that only actually runs (including tool execution) when the response is iterated, later, after the frame is already gone. An already-approved tool call inside a stream therefore fails closed with `ApprovalOutcome::InvalidState`. This is the concrete bug ADR 0006 names as the reason streaming approval resumption doesn't work today.

**Architecture:** `ApprovalExecutionContext` gains `push()`/`pop()` as primitives alongside its existing `within()` (unchanged — `within()` still has real callers outside this fix, see Global Constraints). `VerdictApprovalMiddleware::handle()` still pushes eagerly before calling `$next($prompt)` — required for the synchronous case, where tool execution happens *during* that call — but as soon as the result is known to be a `StreamableAgentResponse`, it immediately undoes that push. The frame is then re-pushed only when the stream is actually iterated: the middleware mutates the *existing* response's `generator` property **in place**, via Reflection, wrapping it in a closure that pushes at the start of iteration and pops in a `finally` block, so the pop fires on normal completion, on an exception partway through, or (via PHP's generator-destruction semantics) if iteration is abandoned before finishing. Because the eager push around `$next()` is always balanced by the time the wrapping method runs, a failure inside that method (e.g. the reflected property isn't a `Closure`) leaves nothing further to clean up.

Mutating `generator` in place, rather than constructing a replacement `StreamableAgentResponse`, is deliberate: the object also carries `conversationId`/`conversationUser`, `then()` callbacks, Vercel-protocol configuration, and cached `events` state, none of which this middleware has any way to faithfully reconstruct, and any of which an inner middleware may already have set before this one sees the response. `generator` is not `readonly` (confirmed against the installed `laravel/ai` source), so in-place mutation via `ReflectionProperty::setValue()` is valid. Only `generator` needs to be read and type-checked via Reflection — `meta` is never touched, since the object itself, not a copy of its state, is what gets returned. No change to `AbstractVerdictTool`, `BoundTool`, `GuardedTool`, or anything downstream of the frame.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4, `laravel/ai` `^0.10.2` (installed `v0.10.3`) — same as the rest of this repo.

## Global Constraints

- PHP `^8.3`, `declare(strict_types=1)` in every modified file.
- 100% type coverage enforced by `composer test` (`pest --type-coverage --min=100`).
- `final`/`final readonly` classes, constructor property promotion, named arguments — match existing style exactly.
- **`ApprovalExecutionContext::within(Decisions, Closure): mixed` must NOT be removed or have its behavior changed.** It has real callers today outside `VerdictApprovalMiddleware`: `workbench/app/Storefront/StorefrontScenarioRunner.php` calls it directly at 6 call sites (lines 161, 165, 170, 633, 684, 699), simulating synchronous approval scenarios without going through the Laravel AI middleware pipeline at all. `push()`/`pop()` are new, additive methods; `within()` may be refactored internally to call them (to avoid duplicating the approved-map-building logic) but its public signature and synchronous push-then-pop-in-finally behavior must be observably identical to today.
- **The reflection touches exactly one property and nothing else.** `StreamableAgentResponse`'s constructor is `(public string $invocationId, protected Closure $generator, protected ?Meta $meta = null)` — only `generator` is read and rewritten via Reflection. `meta` is never touched, and `invocationId` is already public.
- **The response object returned by the middleware must be the same object `$next($prompt)` produced** — `===` identical, not a reconstruction — so that any state an inner middleware already set on it (conversation binding, `then()` callbacks, Vercel protocol config) survives untouched.
- **The value read via Reflection must be type-checked before use**, throwing a `LogicException` with a clear message if it isn't a `Closure`. This is the load-bearing property that makes this fix's fragility "loud break, not silent misbehavior" — do not skip it to save a few lines.
- **The approval frame must never be pushed without a guaranteed matching pop**, including when stream setup itself fails (e.g. the reflected property isn't a `Closure`) and including when a streamed response is returned but never iterated at all.
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
- Consumes: `ApprovalExecutionContext::push()`/`pop()` (Task 1). `Laravel\Ai\Responses\StreamableAgentResponse` (new import — constructor `(string $invocationId, Closure $generator, ?Meta $meta = null)`, `protected Closure $generator`, not `readonly`). `Laravel\Ai\Streaming\Events\StreamEnd`, `Laravel\Ai\Responses\Data\Usage`, `Laravel\Ai\Responses\Data\Meta` (test-only, to build a real terminal stream event and a real response).
- Produces: `VerdictApprovalMiddleware::handle()`'s observable behavior is unchanged for every non-streamed case (identical to today, verified by the existing `ApprovalFlowTest.php` suite passing unmodified). For a streamed `$next($prompt)` result, the approval frame now stays pushed only while the returned response is actively being iterated — pushed right as iteration begins, popped when it ends (completion, exception, or abandonment) — and never leaks into the request scope beyond that. The exact same response object `$next($prompt)` returned is what `handle()` returns; nothing is reconstructed.

This is the core of the fix. Follow strict red-green-commit for the primary regression test (Step 1-4). The two additional tests in Step 5 codify properties the fix must hold that have no meaningful "red" state against the *original* bug (see the note in Step 5) — write them once the implementation exists and confirm they pass immediately; if either fails, that is a bug in the new implementation to fix before moving on, not an ordering mistake.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ApprovalFlowTest.php` already has (read them before editing, to place your new code correctly and match exact conventions): `approvalTool()` (a helper building a real `BoundTool` with a capability that `requiresConfirmation`), `approvalPrompt(Decisions $decisions): AgentPrompt`, and `executeWithinApprovalMiddleware(Tool $tool, Request $request, Decisions $decisions): string` (calls `app(VerdictApprovalMiddleware::class)->handle($prompt, fn () => $tool->handle($request))` synchronously). Reuse `approvalTool()` and `approvalPrompt()` as-is. Do not add a shared "as-stream" helper function — the three new tests below each need a differently-shaped generator (one tool call then a terminal event; one terminal event only, never iterated; one terminal event then a throw), so each constructs its own `StreamableAgentResponse` inline. This matches the file's existing style, which already calls `app(VerdictApprovalMiddleware::class)->handle(...)` directly in some tests rather than exclusively through a helper.

Add the following imports at the top of the file, alongside the existing ones:

```php
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
```

`Meta` and `Usage` both have fully-optional, zero-default constructors (`new Meta`, `new Usage` are both valid on their own — confirmed against the installed `laravel/ai` source), so the tests below construct them directly with no arguments. `StreamEnd`'s constructor is `(string $id, string $reason, Usage $usage, int $timestamp)` — all four required, none defaulted.

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

    $response = app(VerdictApprovalMiddleware::class)->handle(
        approvalPrompt($decisions),
        fn (): StreamableAgentResponse => new StreamableAgentResponse(
            invocationId: 'inv-streamed-approval',
            generator: function () use ($tool, $request): Generator {
                $tool->handle($request);

                yield new StreamEnd('evt-streamed-approval', 'stop', new Usage, time());
            },
            meta: new Meta,
        ),
    );

    // The tool must not have run yet — nothing has iterated $response. If this fails, the
    // test itself is broken (StreamableAgentResponse::getIterator() isn't actually lazy
    // the way this test assumes), not the fix.
    expect($executions)->toBe(0);

    $events = iterator_to_array($response);

    // With the pre-fix code, the approval frame is already popped by the time this
    // iteration runs handle() inside the generator, so the tool sees InvalidState and
    // never increments $executions. With the fix, the frame is still present.
    expect($executions)->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(StreamEnd::class);
});
```

- [ ] **Step 2: Run to verify it fails, and confirm it fails for the right reason**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php --filter="keeps an approved tool call approved through a streamed response"`
Expected: FAIL on `expect($executions)->toBe(1)` (it stays `0` — the tool saw `InvalidState` and did not execute), with the `$events` assertions still passing (the generator yields the `StreamEnd` unconditionally, so the event shape is identical whether or not the tool executed). If instead you get a `TypeError` or other harness error — not a clean assertion failure — the test itself is broken (e.g. `Meta`/`Usage` misconstructed); fix that before proceeding, since you have not yet reproduced the bug.

- [ ] **Step 3: Implement the fix**

Replace the full contents of `src/LaravelAi/VerdictApprovalMiddleware.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Generator;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;
use ReflectionProperty;
use Throwable;

final readonly class VerdictApprovalMiddleware
{
    public function __construct(private ApprovalExecutionContext $context) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $decisions = $prompt->approvalDecisions;

        if ($decisions === null) {
            return $next($prompt);
        }

        $this->context->push($decisions);

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

        // A streamed response is lazy: $next($prompt) has already returned, but the
        // underlying generator — and any tool call inside it — has not run yet. The push
        // above was necessary in case $next() executed a tool synchronously (the
        // non-streamed path above), but it must not survive past this point for a streamed
        // response: if the caller never iterates it, that eager push would otherwise sit on
        // the stack for the rest of this request's scope, visible to unrelated later
        // checks. Undo it here, then re-push only when — and if — real iteration begins.
        $this->context->pop();

        $this->wrapWithDeferredApproval($response, $decisions);

        return $response;
    }

    /**
     * Mutates $response's generator in place so the approval frame is pushed only when the
     * stream is actually iterated, and popped when that iteration ends — on normal
     * completion, on an exception partway through, or (via PHP's generator cleanup) if
     * iteration is abandoned before finishing. Mutating in place, rather than constructing
     * a replacement StreamableAgentResponse, preserves every other piece of state the
     * object may already carry (conversation binding, then() callbacks registered by an
     * inner middleware, Vercel protocol configuration, and so on) — none of which this
     * class has any way to faithfully reconstruct.
     *
     * StreamableAgentResponse exposes no accessor or mutator for its generator, so this
     * reads and rewrites the protected property via Reflection, type-checking the value
     * read back before use. If a future Laravel AI release renames or restructures that
     * property, this throws — a ReflectionException, or the LogicException below —
     * rather than silently misbehaving.
     */
    private function wrapWithDeferredApproval(StreamableAgentResponse $response, Decisions $decisions): void
    {
        $property = new ReflectionProperty(StreamableAgentResponse::class, 'generator');
        $originalGenerator = $property->getValue($response);

        if (! $originalGenerator instanceof Closure) {
            throw new LogicException('Expected Laravel AI\'s StreamableAgentResponse to hold a Closure generator.');
        }

        $property->setValue($response, function () use ($originalGenerator, $decisions): Generator {
            $this->context->push($decisions);

            try {
                yield from call_user_func($originalGenerator);
            } finally {
                $this->context->pop();
            }
        });
    }
}
```

- [ ] **Step 4: Run to verify the new test passes**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php --filter="keeps an approved tool call approved through a streamed response"`
Expected: PASS.

- [ ] **Step 5: Add tests for an unconsumed stream and an interrupted iteration**

These codify two properties the fix must hold beyond the primary regression. Add both after the test from Step 1:

```php
it('does not leave the approval frame active if the streamed response is never iterated', function (): void {
    $decisions = Decisions::from(['call-unconsumed-stream' => Decision::approve()]);

    app(VerdictApprovalMiddleware::class)->handle(
        approvalPrompt($decisions),
        fn (): StreamableAgentResponse => new StreamableAgentResponse(
            invocationId: 'inv-unconsumed',
            generator: function (): Generator {
                yield new StreamEnd('evt-unconsumed', 'stop', new Usage, time());
            },
            meta: new Meta,
        ),
    );

    // Deliberately never iterate the returned response.

    expect(app(ApprovalExecutionContext::class)->allows('call-unconsumed-stream'))->toBeFalse();
});

it('pops the approval frame even when the stream throws partway through iteration', function (): void {
    $decisions = Decisions::from(['call-interrupted-stream' => Decision::approve()]);

    $response = app(VerdictApprovalMiddleware::class)->handle(
        approvalPrompt($decisions),
        fn (): StreamableAgentResponse => new StreamableAgentResponse(
            invocationId: 'inv-interrupted',
            generator: function (): Generator {
                yield new StreamEnd('evt-interrupted', 'stop', new Usage, time());

                throw new RuntimeException('simulated provider failure mid-stream');
            },
            meta: new Meta,
        ),
    );

    expect(fn () => iterator_to_array($response))
        ->toThrow(RuntimeException::class, 'simulated provider failure mid-stream');

    expect(app(ApprovalExecutionContext::class)->allows('call-interrupted-stream'))->toBeFalse();
});
```

Note on these two tests' relationship to "red-green": under the pre-fix code (`within()` popping in its `finally` immediately after `$next($prompt)` returns), both would already pass, because the old code pops before iteration in every case — it never leaks *and* never survives to see an exception either, since it isn't present during iteration at all. These two tests aren't regression tests against the original bug; they're safety-net tests for the *new* implementation's own correctness (no leak on non-consumption, exception-safety of the wrapper's own `try`/`finally`). Run them now and confirm both PASS immediately:

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php --filter="does not leave the approval frame active|pops the approval frame even when the stream throws"`
Expected: PASS (2 tests). If either fails, the implementation has a bug — fix it before proceeding; do not adjust the test to match broken behavior.

- [ ] **Step 6: Run the full existing approval suite to confirm no regression on the synchronous path**

Run: `vendor/bin/pest tests/Feature/ApprovalFlowTest.php`
Expected: PASS, same count as before plus 3 (the three new tests). Pay particular attention to `'requires an exact durable approval before executing and consumes it once'` and `'does not accept wildcard or edited Laravel approval decisions'` — these are the tests most likely to reveal a subtle behavioral change if `push()`/`pop()` don't compute the approved-map identically to what `within()` used to do inline.

- [ ] **Step 7: Run static analysis specifically**

Run: `vendor/bin/phpstan analyse src/LaravelAi/VerdictApprovalMiddleware.php --memory-limit=1G`
Expected: `[OK] No errors`. If PHPStan complains about `ReflectionProperty::getValue()`'s `mixed` return type flowing into the `instanceof` check, that's expected to resolve automatically post-narrowing — but confirm with a real run rather than assuming.

- [ ] **Step 8: Commit**

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

**Revision note (2026-08-10):** this plan was revised before implementation began, in response to review of an earlier draft. That draft pushed the approval frame eagerly and popped it only inside the replacement generator's `finally`, which leaked the frame for the lifetime of the request if a streamed response was constructed but never iterated, and left no guaranteed pop if the reflection-based wrapping step itself failed after the eager push. It also reconstructed `StreamableAgentResponse` from scratch (`invocationId`, `generator`, `meta` only), silently discarding any `conversationId`/`conversationUser`, `then()` callbacks, or Vercel-protocol configuration an inner middleware had already set. And its planned regression test constructed `StreamableAgentResponse` without a `meta` argument, which would `TypeError` inside `getIterator()`'s `new StreamedAgentResponse(...)` call (non-nullable `Meta` there) rather than reaching the test's intended assertion. Task 2 as written here fixes all three: push is deferred to the moment iteration actually begins (Step 3's `wrapWithDeferredApproval()`), the frame is provably balanced at every exit point including the eager-push-then-immediate-undo for a streamed result, the response object is mutated in place rather than rebuilt, and the tests (Step 1 and Step 5) construct real `Meta`/`Usage`/`StreamEnd` instances and cover the unconsumed-stream and interrupted-iteration cases explicitly.
