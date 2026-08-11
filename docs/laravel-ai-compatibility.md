# Laravel AI dependency surface

Verdict pins `laravel/ai: ^0.10.2` — pre-1.0, Composer-caret-pinned to `>=0.10.2 <0.11.0`. This document inventories every place Verdict's `src/` depends on that package's surface, classifies each dependency by how likely it is to change without warning, and — for the dependencies that could break silently — names the test that would catch it.

## Methodology and its limit

This is a grep-and-read audit of `src/`, not a promise from upstream. `laravel/ai` carries no `@api`, `@internal`, or `@experimental` annotations anywhere in its contracts, events, or prompt classes (checked directly against the installed `v0.10.3`) — it does not itself declare which parts of its surface are meant to be extended versus which are implementation detail. The classification below is Verdict's own judgment, inferred from shape (formal interface vs. concrete class vs. framework pipeline convention), not an upstream commitment. Treat "stable" below as "the part of the surface a Tool/Agent SDK integration would reasonably have to depend on," not "guaranteed unchanged."

## Classification legend

- **(a) Documented contract** — a `Laravel\Ai\Contracts\*` interface, or a value object required by such an interface's own signature. The kind of surface an SDK integration has no choice but to depend on.
- **(b) Concrete class, no contract** — a class Verdict calls directly that isn't behind an interface. Laravel AI can change its constructor, methods, or properties in any pre-1.0 release without a major-version signal.
- **(c) Pipeline/event behavior** — Verdict depends on *when* and *with what* Laravel AI invokes a listener or middleware, not on a class or interface at all. The riskiest category, because there's no single symbol to grep for when it changes.

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
| `Events\ToolInvoked` | (c) | `VerdictServiceProvider`, `RecordToolResultProvenance::handle()` | `invocationId`, `toolInvocationId`, `agent`, `tool`, `arguments`, `result`. This is the event at the center of the known `toolInvocationId` nesting defect — see [Cross-reference](#cross-reference-composerjson-and-the-existing-compatibility-watch) below. |
| `Approvals\Decisions` | (b) | `ApprovalExecutionContext::within()`, `VerdictApprovalMiddleware`, `AbstractVerdictTool` (docblock) | Concrete class. `->all()` is iterated and each value's `->isApproved()` is called (duck-typed against `Approvals\Decision`, which is never imported in `src/` — see [Corrections](#corrections-to-the-issue-draft) below). |

**`handle(AgentPrompt $prompt, Closure $next): mixed`** — `VerdictApprovalMiddleware` and `VerdictProvenanceMiddleware` both implement this signature as Laravel AI prompt middleware. This is category (c) in its purest form: there is no `Contracts\Middleware` interface to implement or grep for. The signature convention (`handle($passable, Closure $next)`) mirrors Laravel's own HTTP/job middleware idiom, which is a reasonable inference, not a documented Laravel AI promise.

## Category (c) deep dives

### Agent identity across the prompt lifecycle

`PromptProvenanceRegistry` keys a `WeakMap<Agent, …>` by the `Agent` instance it receives from `VerdictProvenanceMiddleware::handle()`'s `$prompt->agent`, and later looks that same key up from `RecordAgentPromptProvenance::handle()`'s `$event->prompt->agent` — a *different* `AgentPrompt` instance, arriving later, via a `PromptingAgent` event. This only works if Laravel AI hands Verdict the identical `Agent` object at both points. Nothing in `Contracts\Agent` promises that; it's inferred from observed behavior.

**Existing test coverage:** `tests/Feature/LaravelAiProvenanceTest.php`, `'fails closed instead of ambiguously correlating overlapping prompts for one agent'`. This proves Verdict's *own* registry logic is correct *given* a fixed `Agent` instance (it uses `Mockery::mock(Agent::class)` and passes the same mock to two prompts). It does **not** prove Laravel AI's real runtime actually preserves `Agent` identity across the prompt→event lifecycle — mocking sidesteps that question entirely. There is currently no test that exercises this assumption against real Laravel AI internals the way `tests/Feature/ToolInvocationCorrelationTest.php` does for invocation-id correlation (see below). **Proposed:** extend `ToolInvocationCorrelationTest.php`'s real-`Ai`/`FakeTextGateway` harness (it already drives a genuine Laravel AI prompt→tool-invocation cycle) to assert that the `Agent` object captured by `VerdictProvenanceMiddleware` and the one delivered on the eventual `PromptingAgent` event are `===` identical, not just value-equal.

### Prompt-middleware pipeline invocation

`VerdictApprovalMiddleware::handle()` and `VerdictProvenanceMiddleware::handle()` are both tested by constructing an `AgentPrompt` directly and calling `->handle($prompt, $next)` by hand (`tests/Feature/ApprovalFlowTest.php`'s `executeWithinApprovalMiddleware()` helper; `tests/Feature/LaravelAiProvenanceTest.php`'s equivalent). This is real coverage of what the middleware *does* given a well-formed `AgentPrompt`, and the `AgentPrompt` construction itself pins the real constructor signature (`agent`, `prompt`, `attachments`, `provider`, `model`, `timeout`, `invocationId`, `approvalDecisions` — a break there fails these tests immediately). What it does **not** cover: whether Laravel AI's actual prompt pipeline invokes middleware with this exact `handle($prompt, Closure $next): mixed` calling convention, in this order, with a single `$next` closure — that's simulated, not exercised. **Proposed:** a workbench-level test that registers `VerdictProvenanceMiddleware` through whatever real pipeline-registration mechanism Laravel AI exposes for prompt middleware and drives a prompt through it, rather than invoking `->handle()` directly.

### Wildcard approval decisions

`ApprovalExecutionContext::within()` special-cases a Laravel AI decision keyed `'*'` (`if ($toolCallId !== '*' && $decision->isApproved())`), which corresponds to `Laravel\Ai\Approvals\Decision::approveAll()`. This is genuinely well-covered, not a gap: `tests/Feature/ApprovalFlowTest.php`, `'does not accept wildcard or edited Laravel approval decisions'`, explicitly constructs a wildcard `Decisions` object via `Decision::approveAll()` and asserts Verdict rejects it rather than silently approving every tool call. Listed here because it's exactly the kind of magic-string convention issue #18 asks to surface, and because it's the one place `Laravel\Ai\Approvals\Decision` (singular) is actually used — see the correction below.

## Corrections to the issue draft

Issue #18's own "known touch points" list was explicitly a quick grep pass, not the audit — two things in it don't match what's actually in the tree today:

- **`Laravel\Ai\Approvals\Decision` (singular)** is not referenced anywhere in `src/`. It appears only in `tests/Feature/ApprovalFlowTest.php`, via `Decision::approveAll()`, as the deliberate wildcard-rejection test described above. `src/` only ever imports `Decisions` (plural).
- **The quoted README passage** — "Laravel AI is pre-1.0, so Verdict verifies its adapter against released public contracts and should expect compatibility work as that SDK changes," cited at `README.md:1334-1336` — no longer exists anywhere in the repository (`grep -rl` across all `*.md` files returns nothing). Likewise, the "complete compatibility matrix" roadmap gate the issue cites from the old README no longer exists there either. Both were apparently carried over from the README's pre-restructure state. The compatibility-matrix concept survives, just relocated: it's tracked as its own issue, [#19](https://github.com/fissible/verdict/issues/19) ("Add consolidated ordering table and streaming/queued compatibility matrix"), and the day-to-day upstream watch lives in `MILESTONES.md`'s "Upstream dependency watch" section, not README. This audit is the inventory that section assumed existed; #19 remains the separate, not-yet-done deliverable for the matrix itself.

## Cross-reference: composer.json and the existing compatibility watch

`MILESTONES.md`'s "Upstream dependency watch" section already documents the one *known* incompatibility in play, and this audit defers to it rather than duplicating it:

- `laravel/ai#848` (open) fixes a `toolInvocationId` nesting/clobber defect. It's minor-bump shaped, so widening past it is a deliberate, reviewed act, not something a patch update silently absorbs.
- `tests/Feature/ToolInvocationCorrelationTest.php` deliberately pins the *current, buggy* nested-invocation behavior. Per that file's own framing (restated in `MILESTONES.md`), the test going red when the constraint widens is the designed alarm, not a regression to panic about.
- This is precisely the "named test that would fail if the underlying behavior changed incompatibly" pattern issue #18 asks category (c) items to have. It already exists for the one dependency where Verdict has hard evidence (not just inference) that the assumption is fragile — `MILESTONES.md` records that this issue was pulled forward specifically because measuring #855/#848 surfaced an undocumented correlation assumption while the findings were fresh.

Nothing found in this audit is used in `src/` but absent from that existing watch list, and no `laravel/ai: ^0.10.2` surface Verdict depends on falls outside what `composer show laravel/ai` reports as installed (`v0.10.3`).

## Summary

No source changes are required by this audit — every category (c) assumption found either already has real test coverage exercising Verdict's own logic (agent identity, wildcard rejection) or a deliberately-red compatibility alarm for a known upstream defect (invocation-id nesting). The two gaps are in test *reach*, not correctness: neither the agent-identity assumption nor the middleware pipeline-invocation convention is currently verified against Laravel AI's real runtime behavior, only against hand-constructed inputs. Both are named above as proposed extensions to existing tests, not new source-level fixes, consistent with this issue's acceptance criteria.
