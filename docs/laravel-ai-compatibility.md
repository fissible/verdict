# Laravel AI dependency surface

Verdict pins `laravel/ai: ^0.11.0` — pre-1.0, Composer-caret-pinned to `>=0.11.0 <0.12.0`. **`0.10.x` is no longer supported**, and the range is stated rather than widened by reflex: `0.11.0`'s [#874](https://github.com/laravel/ai/pull/874) made `float $time` a required seventh argument on `Events\ToolInvoked`, so one test construction cannot satisfy both floors. Supporting both would mean version-conditional test code for no adopter benefit. This document inventories every place Verdict's `src/` depends on that package's surface, classifies each dependency by how likely it is to change without warning, and — for the dependencies that could break silently — names the test that would catch it.

## Methodology and its limit

This is a grep-and-read audit of `src/`, not a promise from upstream. `laravel/ai` carries no `@api`, `@internal`, or `@experimental` annotations anywhere in its contracts, events, or prompt classes (checked directly against the installed `v0.11.0`) — it does not itself declare which parts of its surface are meant to be extended versus which are implementation detail. The classification below is Verdict's own judgment, inferred from shape (formal interface vs. concrete class vs. framework pipeline convention), not an upstream commitment. Treat "stable" as "the part of the surface a Tool/Agent SDK integration would reasonably have to depend on," not "guaranteed unchanged."

## Classification legend

- **(a) Documented contract** — a `Laravel\Ai\Contracts\*` interface, or a value object required by such an interface's own signature. The kind of surface an SDK integration has no choice but to depend on.
- **(b) Concrete class, no contract** — a class Verdict calls directly that isn't behind an interface. Laravel AI can change its constructor, methods, or properties in any pre-1.0 release without a major-version signal.
- **(c) Pipeline/event behavior** — Verdict depends on *when* and *with what* Laravel AI invokes a listener or middleware, not on a class or interface at all. The riskiest category, because there's no single symbol to grep for when it changes.

## Compatibility matrix

This is a generated record of observations, not a hand-maintained support promise. `ci`
means the named GitHub Actions run tested the row; `local` records one local suite run, and its
locator is the record of that run — what was checked out, what resolved, and what the suite
reported — not the manifest of the release it names. The distinction is the point: a `ci` row is a
swept matrix, a `local` row is one resolution on one machine on one date, and neither word turns a
Composer constraint into evidence.

The block comes from `scripts/generate-compatibility-matrix.php` and its JSON facts input. Regenerate
it whenever the `laravel/ai` constraint changes or when adding a newly observed row. Rows are retired
or pruned when their concrete Laravel AI version no longer satisfies the composer.json constraint;
this keeps Verdict, not another repository's release cadence, responsible for the boundary.

The verdict-console facts below are reported by its manifest and VERSION at the linked revision, not
verified here: Verdict's CI does not run the console suite. That manifest declares
`fissible/verdict: ^0.12` and `laravel/ai: ^0.11.0`; the console's own 24-cell workflow resolves
both packages per cell rather than pinning either one.

<!-- generated:compatibility-matrix -->
| verdict | verdict-console | laravel/ai | php | laravel | verified | date | evidence |
|---|---|---|---|---|---|---|---|
| v0.12.0 | v0.2.0 (https://github.com/fissible/verdict-console/commit/f9e0848f1ca9118b6e0b194a67ee026b27db70d3) | v0.11.0 | 8.4.24 | 13.29.0 | local | 2026-08-27 | https://github.com/fissible/verdict/blob/9469d46046b6b722446810f1d2970e4dba3900af/compatibility/evidence/v0.12.0.md |
<!-- /generated:compatibility-matrix -->

## Symbol inventory

Every `Laravel\Ai\*` symbol referenced anywhere in `src/` (verified via `grep -rn "Laravel\\Ai\\" src/ --include="*.php"`; two additional inline fully-qualified references in `src/Facades/Verdict.php`'s docblocks are folded into the `Tool` row since they're the same contract, referenced for IDE hinting only, with no runtime effect):

| Symbol | Class | Where used in `src/` | Notes |
|---|---|---|---|
| `Contracts\Tool` | (a) | `AbstractVerdictTool implements`, `VerdictManager::guard()`/`bound()`, `Facades\Verdict` docblock | The interface every Laravel AI tool implements. `handle(Request): Stringable\|string`, `description()`, `schema()`. |
| `Contracts\Approvable` | (a) | `AbstractVerdictTool implements` | Optional interface for tools that can require approval. `shouldRequestApproval(Request): ?Approval`. |
| `Tools\Request` | (a) | `AbstractVerdictTool`, `BoundTool`, `GuardedTool`, `VerdictManager` (docblock) | The parameter type of `Tool::handle()` — tied to the `Tool` contract itself, not an independent dependency. |
| `Approvals\Approval` | (a) | `AbstractVerdictTool::requireApproval()`/`shouldRequestApproval()` | Return type of `Approvable::shouldRequestApproval()` — same reasoning as `Request`. |
| `Tools\ToolNameResolver` | (b) | `AbstractVerdictTool::name()` — `ToolNameResolver::resolve($this->tool)` | A static helper, not part of any interface. Laravel AI could change how tool names are derived (or remove the helper) without a contract change. |
| `Contracts\Agent` | (a) type / (c) assumption | `PromptProvenanceRegistry` (`WeakMap<Agent, …>` key), `VerdictProvenanceMiddleware::handle()` (`$prompt->agent`) | The interface itself is stable documented surface — but Verdict's actual dependency is on an **undocumented behavioral property**: that Laravel AI hands back the *same* `Agent` object instance across the initial prompt and the later `PromptingAgent`/`ToolInvoked` events for one invocation. Nothing in the `Agent` interface promises object identity. See [Category (c) deep dive: Agent identity](#agent-identity-across-the-prompt-lifecycle) below. |
| `Prompts\AgentPrompt` | (b) | `VerdictApprovalMiddleware::handle()`, `VerdictProvenanceMiddleware::handle()`, `RecordAgentPromptProvenance::handle()` (via `$event->prompt`) | Concrete class (`class AgentPrompt extends Prompt`), no interface at all. Verdict reads `$prompt->invocationId`, `$prompt->prompt`, `$prompt->agent`, `$prompt->approvalDecisions`, and calls `$prompt->hasApprovalDecisions()`. Every one of these is a public/readonly property or method on a concrete class Laravel AI is free to restructure pre-1.0. |
| `Events\PromptingAgent` | (c) | `VerdictServiceProvider` (`$events->listen(PromptingAgent::class, RecordAgentPromptProvenance::class)`), `RecordAgentPromptProvenance::handle()` | A plain event class (`invocationId`, `prompt`) — the risk isn't the class shape, it's *when* Laravel AI fires it relative to prompt middleware and tool invocation. See the deep dive below. |
| `Events\ToolInvoked` | (c) | `VerdictServiceProvider`, `RecordToolResultProvenance::handle()` | `invocationId`, `agent`, `tool`, `result`. Gained a required `float $time` in `0.11.0` ([#874](https://github.com/laravel/ai/pull/874)) — the change that set this package's floor. Verdict's listener reads neither `time` nor `toolInvocationId`, so it was unaffected; the two hand-constructed events in `LaravelAiProvenanceTest` were not, which is why a breaking change here is loud. The `toolInvocationId` nesting defect this event was previously at the centre of is **fixed** in `0.11.0` — see [What 0.11.0 changed](#what-0110-changed-and-what-it-did-not). |
| `Approvals\Decisions` | (b) | `LaravelApprovalDecisions` | Concrete class. The adapter iterates `->all()` and calls each value's `->isApproved()` (duck-typed against `Approvals\Decision`, which is never imported in `src/` — see [Corrections](#corrections-to-the-issue-draft) below). |

**`handle(AgentPrompt $prompt, Closure $next): mixed`** — `VerdictApprovalMiddleware` and `VerdictProvenanceMiddleware` both implement this signature as Laravel AI prompt middleware. This is category (c) in its purest form: there is no `Contracts\Middleware` interface to implement or grep for. The signature convention (`handle($passable, Closure $next)`) mirrors Laravel's own HTTP/job middleware idiom, which is a reasonable inference, not a documented Laravel AI promise.

## Test-surface dependency: `Contracts\Gateway\StepTextGateway`

One symbol is deliberately absent from the table above because it appears nowhere in `src/`, yet belongs in this inventory: `Laravel\Ai\Contracts\Gateway\StepTextGateway`, category **(a)** by shape (a documented contract interface). Four test files implement it — `StreamedApprovalResumptionTest`, `QueuedApprovalResumptionTest`, `StreamedExecutionGatesTest`, and `LiveAgentObserverStreamingTest` — because it is the only substitution that drives Laravel AI's real `stream()`/resume pipeline with controlled provider output; `Agent::fake()` never resumes tools ([#233](https://github.com/fissible/verdict/pull/233)).

It is inventoried despite living outside `src/` because two public-facing artifacts rest on its stability: the verified streamed and queued approval-resumption cells in the [execution-mode compatibility matrix](architecture.md#execution-mode-compatibility) ([#233](https://github.com/fissible/verdict/pull/233)/[#235](https://github.com/fissible/verdict/pull/235)), and the reference application's default replay mode ([#237](https://github.com/fissible/verdict/issues/237)), which implements this contract to demonstrate the boundary with no live model. An upstream signature change surfaces through the same watch as everything else here: the four tests fail immediately (the designed alarm), the canary gives lead time, and the response is the [#130](https://github.com/fissible/verdict/issues/130) checklist. The reference application pins tagged releases, so it absorbs such a change at a reviewed version bump, not in the field.

## Category (c) deep dives

### Bound-tool preflight and immediate execution

Laravel AI invokes an `Approvable` tool's `shouldRequestApproval(Request)` before its immediate
`handle(Request)` call, with distinct `Request` instances for the same provider tool call. For a
`BoundTool` with a callable Verdict `ActionContext`, Verdict prepares one envelope during a
non-approval preflight and consumes it at immediate execution. This prevents the two hooks from
resolving different actors or subjects for one tool call, while retaining proposal- and
execution-stage authorization.

The prepared envelope is held only in Verdict's scoped `InvocationContext`, keyed by its
framework-provided current invocation ID and the non-empty provider tool-call ID. It is neither a
tool-instance field nor an application cache: direct or middleware-less calls have no invocation
frame and resolve fresh. A mismatched argument payload discards the entry, as do denied and
exceptional handle paths. Popping the last frame for an invocation clears its entries, so a lazy
stream that is abandoned cannot retain data for a later request.

Approval resumes are deliberately excluded. The provenance middleware establishes the same
invocation frame while handling approval decisions for correlation, but a verified decision causes
`BoundTool::handle()` to discard any matching prepared entry and rebuild the envelope. This
preserves fresh target, binding, context, and execution-stage authorization after a durable human
approval wait. Nested agent generations use the innermost invocation frame, so an outer
prepared envelope is invisible to a sub-agent call even when a provider reuses the tool-call ID.

**Coverage:** `tests/Feature/StreamedExecutionGatesTest.php` proves one resolver result reaches
both authorizations and the executor during real lazy streaming; `tests/Feature/ApprovalFlowTest.php`
proves approval resume resolves fresh; `tests/Feature/BoundToolTest.php` covers no-frame,
argument-mismatch, denied, and exceptional paths; `tests/Unit/InvocationContextTest.php` covers
nested and unwound-frame isolation.

### Agent identity across the prompt lifecycle

`PromptProvenanceRegistry` keys a `WeakMap<Agent, …>` by the `Agent` instance it receives from `VerdictProvenanceMiddleware::handle()`'s `$prompt->agent`, and later looks that same key up from `RecordAgentPromptProvenance::handle()`'s `$event->prompt->agent` — a *different* `AgentPrompt` instance, arriving later, via a `PromptingAgent` event. This only works if Laravel AI hands Verdict the identical `Agent` object at both points. Nothing in `Contracts\Agent` promises that; it's inferred from observed behavior.

**Contract coverage:** `tests/Contract/AgentIdentityAcrossLifecycleContractTest.php` is the named real-runtime alarm for this lifecycle assumption. `tests/Feature/LaravelAiProvenanceTest.php`, `'fails closed instead of ambiguously correlating overlapping prompts for one agent'`, remains the constructed regression test for Verdict's registry behavior with a fixed agent instance.

### Prompt-middleware pipeline invocation

`tests/Contract/MiddlewarePipelineInvocationContractTest.php` is the named real-runtime alarm for Laravel AI's prompt-pipeline registration and invocation seam. The constructed middleware tests remain focused on Verdict's behavior given a well-formed `AgentPrompt`; the contract suite makes the upstream pipeline dependency explicit and runs it in both canary cells.

### Wildcard approval decisions

`LaravelApprovalDecisions` special-cases a Laravel AI decision keyed `'*'` (`if ($toolCallId !== '*' && $decision->isApproved())`), which corresponds to `Laravel\Ai\Approvals\Decision::approveAll()`. This is genuinely well-covered, not a gap: `tests/Feature/ApprovalFlowTest.php`, `'silently skips a wildcard approval, requiring a specific decision'`, explicitly constructs a wildcard `Decisions` object via `Decision::approveAll()` and asserts Verdict silently skips it rather than approving every tool call, requiring a specific decision. Listed here because it's exactly the kind of magic-string convention issue #18 asks to surface, and because it's the one place `Laravel\Ai\Approvals\Decision` (singular) is actually used — see the correction below.

### Edited approval decisions

`LaravelApprovalDecisions` refuses an edited approval decision by throwing `UnsupportedApprovalDecision`; adopters should catch that exception and resume with `Decision::approve()` for the original proposal. This is intentionally not a silent drop: Verdict receipts bind the original arguments, while an edited decision requests different ones. By contrast, the `'*'` wildcard remains silently skipped so streamed and queued resumption does not treat it as an explicit approval.

## Corrections to the issue draft

Issue #18's own "known touch points" list was explicitly a quick grep pass, not the audit — two things in it don't match what's actually in the tree today:

- **`Laravel\Ai\Approvals\Decision` (singular)** is not referenced anywhere in `src/`. It appears only in `tests/Feature/ApprovalFlowTest.php`, via `Decision::approveAll()`, as the deliberate wildcard-rejection test described above. `src/` only ever imports `Decisions` (plural).
- **The quoted README passage** — "Laravel AI is pre-1.0, so Verdict verifies its adapter against released public contracts and should expect compatibility work as that SDK changes," cited at `README.md:1334-1336` — no longer exists anywhere in the repository (`grep -rl` across all `*.md` files returns nothing). Likewise, the "complete compatibility matrix" roadmap gate the issue cites from the old README no longer exists there either. Both were apparently carried over from the README's pre-restructure state. The compatibility-matrix concept survives, just relocated: it's tracked as its own issue, [#19](https://github.com/fissible/verdict/issues/19) ("Add consolidated ordering table and streaming/queued compatibility matrix"), and the day-to-day upstream watch lives in `MILESTONES.md`'s "Upstream dependency watch" section, not README. This audit is the inventory that section assumed existed; #19 remains the separate, not-yet-done deliverable for the matrix itself.

## What `0.11.0` changed, and what it did not

Absorbed in [#130](https://github.com/fissible/verdict/issues/130). Two Verdict tests failed on the
upgrade and nothing else did; PHPStan was clean, which is the signal that no `src/` consumer of a changed
contract needed a signature change.

**The `toolInvocationId` nesting defect is fixed.** `GeneratesText::$currentToolInvocationId` was one
property on a per-name-memoized provider, so a nested generation overwrote it and the *outer* tool's
`ToolInvoked` carried the *inner* tool's id — silently mis-correlating evidence Verdict writes from these
events. [#872](https://github.com/laravel/ai/pull/872) deleted the shared property and scoped the id
through a run context. `ToolInvocationCorrelationTest` pinned the broken behaviour on purpose
([#53](https://github.com/fissible/verdict/pull/53)) so the fix would fail loudly rather than change the
meaning of recorded evidence quietly. That alarm fired, and the assertion now states the fixed behaviour.
The fix shipped as #872; the draft #848 it was split out of was closed as superseded and is **not** the
fix, despite earlier notes citing it as such.

**Invocation ids remain per-run, which is what Verdict's provenance correlates by.**
[#871](https://github.com/laravel/ai/pull/871) threads one invocation id through an entire agent run and
[#875](https://github.com/laravel/ai/pull/875) links a sub-agent back to its parent — but a sub-agent run
still receives its *own* invocation id plus a parent pointer, rather than inheriting the parent's. So
`RecordToolResultProvenance` continues to correlate a sub-agent's tool results to the sub-agent's run.
`ToolInvocationCorrelationTest` asserts this directly rather than leaving it inferred.

**A two-turn approval resume still mints two invocation ids.** Both `Promptable::prompt()` and
`Promptable::stream()` mint a `Str::uuid7()` unconditionally per call, so the boundary-spanning key across
an approval pause remains the tool call id, exactly as measured in
[#218](https://github.com/fissible/verdict/issues/218). Nothing about resumed-approval evidence
correlation changed.

**The conversation-history replay path changed without breaking anything.**
[#758](https://github.com/laravel/ai/pull/758) filters partially-orphaned tool calls when replaying
conversation history — the same reconstruction the streamed and queued approval-resumption cells of the
[execution-mode matrix](architecture.md#execution-mode-compatibility) depend on. Both suites pass
unchanged, so those cells' footnotes still hold.

`Events\ToolFailed` is new in `0.11.0` and Verdict does not listen to it, but
`ToolInvocationCorrelationTest` asserts its correlation anyway: it occupies the same trailing-event
position that carried the `ToolInvoked` defect, so if Verdict ever records failure-path evidence, the
guarantee is already pinned rather than assumed.

New surface Verdict neither consumes nor asserts: `Events\StartingStep`, `Events\StepCompleted`,
`Events\StepFailed`, and the run-context objects behind them.

## Cross-reference: composer.json and the existing compatibility watch

`MILESTONES.md`'s "Upstream dependency watch" section already documents the one *known* incompatibility in play, and this audit defers to it rather than duplicating it:

- `laravel/ai#848` (open) fixes a `toolInvocationId` nesting/clobber defect. It's minor-bump shaped, so widening past it is a deliberate, reviewed act, not something a patch update silently absorbs.
- `tests/Feature/ToolInvocationCorrelationTest.php` deliberately pins the *current, buggy* nested-invocation behavior. Per that file's own framing (restated in `MILESTONES.md`), the test going red when the constraint widens is the designed alarm, not a regression to panic about.
- This is precisely the "named test that would fail if the underlying behavior changed incompatibly" pattern issue #18 asks category (c) items to have. It already exists for the one dependency where Verdict has hard evidence (not just inference) that the assumption is fragile — `MILESTONES.md` records that this issue was pulled forward specifically because measuring #855/#848 surfaced an undocumented correlation assumption while the findings were fresh.

Nothing found in this audit is used in `src/` but absent from that existing watch list, and no `laravel/ai: ^0.11.0` surface Verdict depends on falls outside what `composer show laravel/ai` reports as installed (`v0.11.0`).

`.github/workflows/laravel-ai-canary.yml` shortens the delay before that alarm can fire. `^0.11.0` is a ceiling — it resolves `>=0.11.0 <0.12.0` — so nothing shipped in `0.12.0` reaches CI until Dependabot's weekly Composer check opens the widening PR, which cannot happen until upstream *publishes*. The canary installs `laravel/ai:0.x-dev` on a weekly schedule and runs PHPStan and the suite against it, reporting when upstream *merges* instead. That lead time is what matters while a stack is still open and reviewable. It is non-blocking by design: a red canary means *upstream changed*, and the response is the checklist in [#130](https://github.com/fissible/verdict/issues/130), not a reflexive constraint bump. Composer normalizes a branch name that already looks like a version, so the constraint is `0.x-dev`; `dev-0.x` does not resolve.

## Summary

This audit requires no source changes — every category (c) assumption found either already has real test coverage exercising Verdict's own logic (agent identity, wildcard rejection) or a deliberately-red compatibility alarm for a known upstream defect (invocation-id nesting). The two gaps are in test *reach*, not correctness: neither the agent-identity assumption nor the middleware pipeline-invocation convention is currently verified against Laravel AI's real runtime behavior, only against hand-constructed inputs. Both are named above as proposed extensions to existing tests, not new source-level fixes, consistent with this issue's acceptance criteria.
