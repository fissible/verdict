# ADR 0033: laravel/ai is reached through two declared adapter zones, never from the kernel

Status: Proposed

Verified against the tree at the commit this branch is based on. Counts and file locations are
stated with their counting method, because this ADR's central claim is that the coupling is thinner
than it appears, and a claim like that has to be checkable rather than asserted.

## Related issues

- [#324](https://github.com/fissible/verdict/issues/324) is the design round this settles.
- [#18](https://github.com/fissible/verdict/issues/18) (closed) audited the laravel/ai dependency
  surface and produced `docs/laravel-ai-compatibility.md`;
  [#131](https://github.com/fissible/verdict/issues/131) (closed) added the weekly canary. This ADR
  adds what neither did: an isolating boundary and a rule that fails when it is crossed.
- [#53](https://github.com/fissible/verdict/issues/53) and
  [#218](https://github.com/fissible/verdict/issues/218) pinned the invocation-id and resume
  behaviours a contract test must document.
- [#265](https://github.com/fissible/verdict/issues/265) and
  [laravel/ai#932](https://github.com/laravel/ai/pull/932) are the live fault line: participant
  identity for approval resumption, changing in a 0.x minor, in Verdict's approval path.

## Context

Verdict intercepts laravel/ai by implementing and wrapping its contracts. That is the correct
mechanism — there is no designed seam for a security decision boundary to occupy — but it means
upstream types are load-bearing in a package whose upstream is 0.x, with no stability promise and no
deprecation cycle.

**The surface, counted.** Grepping `Laravel\Ai\` across `src/`: **16 distinct fully-qualified
symbols** across **18 files** — **9 inside `src/LaravelAi/`** and **9 outside it**. "Files" counts a
file once however many symbols it names; the two figures are different measurements and are easy to
conflate.

The canary (#131) already answers *whether* something upstream moved: it runs the suite against
`0.x-dev` and goes red. What it cannot answer is *what* broke or *how far* the damage reaches.

The nine outside files are not one problem. They are four kinds, and treating them alike is what
makes this look like a rewrite:

1. **A kernel coupling that collapses to a set of strings.** `ApprovalManager` and
   `ApprovalExecutionContext` accept `Laravel\Ai\Approvals\Decisions`, but
   `ApprovalExecutionContext::push()` reads only `all()`, skips the `'*'` wildcard key, keeps the
   ids for which `isApproved()` holds, and discards everything else. The kernel's entire dependency
   on upstream's approval vocabulary is *which tool-call ids were approved*.
2. **Vocabulary that already exists, misfiled.** `InvocationContext` carries **zero** `Laravel\Ai\*`
   references. Six files outside `src/LaravelAi/` depend on it; under this ADR's own zoning **three
   are kernel** (`VerdictManager`, `ApprovalManager`, `ContextReleaseManager`), two are evaluation,
   one is the provider. It is not coupled to upstream — it is merely located in the adapter
   directory, which is what makes the dependency graph read as kernel → adapter.
3. **A second consumer whose coupling is real and broad.** `src/Evaluation/` has three coupled
   files: `LiveAgentObserver` (classifies runs via `ApprovalNotResumableException`,
   `StreamableAgentResponse`, `AgentResponse`, `StructuredAgentResponse`), and `CapturingTool` and
   `UnguardedCapturingTool`, which *implement* upstream tool contracts and use `Request` and
   `ToolNameResolver`.
4. **Two factory signatures.** `VerdictManager::guard()` and `bound()` name
   `Laravel\Ai\Contracts\Tool`. Both hand the argument straight to `GuardedTool`/`BoundTool` — the
   kernel never calls a method on it.

Remainder: `CapabilitySecurityTestKit` builds `Decisions::from([...])` to simulate a resume,
`VerdictServiceProvider` names three event classes, and `Facades/Verdict` names `Tool` in a
`@method` docblock.

## Decision

### 1. Two declared zones, and the kernel is neither

`Laravel\Ai\*` may be referenced only in:

- **`src/LaravelAi/`** — the interception adapter: tool wrappers, middleware, event listeners.
- **`src/Evaluation/`** — the harness adapter: the capturing tools and the live agent observer.

Every other namespace — `Approvals`, `Capabilities`, `Decisions`, `ExecutionClaims`, `Evidence`,
`Intents`, `RateLimits`, `Context`, `Targets` — is kernel and may not name an upstream type.

**There are exactly three exceptions, all outside the zones, all member-level:**

| Location | Permitted occurrences | Why |
|---|---|---|
| `VerdictManager` | `use` of `Contracts\Tool` and `Tools\Request`; the `Tool` parameters of `guard()` and `bound()`; the two `callable(Request)` docblock annotations on those methods | The designated seam (§4) |
| `Facades\Verdict` | the two `@method` annotations for `guard()` and `bound()` | Mirrors the seam's signatures |
| `VerdictServiceProvider` | the three event classes it registers listeners for | A composition root's job is to know both sides |

**Member-level, not file-level.** Allowing these files wholesale would let upstream references
accumulate in them over time, which is how a boundary rule dies quietly. The architecture test
enumerates the permitted occurrences above and fails on any *additional* upstream reference in the
same file.

**Ownership.** `src/LaravelAi/` owns the interception contract with upstream; `src/Evaluation/` owns
the harness contract. Each zone owns its own translation: a change upstream is diagnosed and
absorbed in the zone that names the changed symbol, and neither zone may push an upstream type
across into the kernel.

### 2. `ApprovedToolCalls`, with its invariants stated

A value object over `list<string>` replaces `Decisions` in `ApprovalManager::withinApprovedToolCalls()`
and `ApprovalExecutionContext`. The adapter performs the one translation. The invariants it must
carry, because they are the behaviour `push()` implements today and a translation is exactly where
such behaviour goes missing:

- **Wildcard is excluded.** Upstream's `'*'` key is not a tool-call id; it must never enter the set.
  A blanket approval reaching the kernel as an id would authorize a call nobody approved.
- **Only approved decisions enter.** A `Decision` that is not `isApproved()` contributes nothing.
- **Frames nest and unwind.** `within()` pushes, runs, and pops in a `finally`; nested frames and
  restoration after an exception are part of the contract, not the caller's problem
  (`tests/Unit/InvocationContextTest.php` already pins the analogous property for invocation frames).

`CapabilitySecurityTestKit` constructs `ApprovedToolCalls` directly rather than assembling
`Decisions`.

### 3. `InvocationContext` moves; no new correlation vocabulary is invented

`InvocationContext` moves out of `src/LaravelAi/` into the kernel. It is already the right type;
only its address is wrong.

#324 proposed a Verdict `ToolCallProposal` / `Correlation` in place of `AgentPrompt`. Rejected on
the audit: no kernel code reads `AgentPrompt`, and the correlation type already exists with no
upstream references. Types are added when a kernel path is found to need one, not in anticipation —
an anti-corruption layer that grows abstractions ahead of evidence becomes its own maintenance
surface, which is the failure mode this ADR exists to avoid.

### 4. `guard()` and `bound()` stay on `VerdictManager`, as the designated seam

This is a reversal of the design round's proposal, on evidence found while writing this ADR.

Moving them was supposed to be free because the facade would delegate. It is not: **63 call sites in
this repository call `VerdictManager::guard()`/`bound()` directly** (`app(VerdictManager::class)->bound(...)`,
`$verdict->bound(...)`) against **4** that go through the facade. Moving the methods is a breaking
change to the package's most-used entry point, and keeping typed delegators on the manager would
violate the zone rule while pretending not to.

Weigh that against what the coupling actually is: both methods hand the argument to
`GuardedTool`/`BoundTool` **without ever dereferencing it**. The kernel names `Tool` in two
signatures and never calls a method on it. An upstream *behaviour* change cannot reach through a
parameter type nothing invokes; only a rename or removal of the type could, and that breaks the
adapter regardless.

So: these two signatures — plus the two `callable(Request)` annotations that describe their context
resolvers, and the `use` statements both require — are an explicit, permanent, member-level exception
to §1, named in the architecture test with this reasoning. They are the **designated seam** — the one place an adopter deliberately hands
a laravel/ai tool to Verdict — and a boundary rule that cannot name its own front door is a rule
that will be worked around. Nominal purity here would cost a breaking change and buy no containment.

`Facades/Verdict`'s two `@method` annotations name `Tool` for the same reason and are covered by
their own entry in the §1 table.

### 5. The rule is enforced by an architecture test

`tests/Unit/LaravelAiBoundaryArchitectureTest.php`, following
`ContextReleaseSideChannelArchitectureTest` and `SecurityStateTransactionArchitectureTest` — the
places this repo already keeps invariants a reviewer must not have to infer. It must:

- scan **imports, inline fully-qualified references, and docblock types**, since a `@param
  \Laravel\Ai\...` annotation couples just as surely as a `use` statement;
- hold the zone list and the three §1 exceptions **in the test with their justifications**, so
  adding one requires writing down why — and enumerate them **member-level**: the two `use`
  statements, two `Tool` parameters and two `callable(Request)` annotations on `VerdictManager`, the
  two facade `@method` lines, and the provider's three event classes. Any further upstream reference
  in those files fails. Note that `Request` appears *only* in docblocks there, so a docblock-blind
  test would pass while a docblock-scanning one would reject the manager unless the annotations are
  named — the exception list and the scanner have to be written against each other;
- **fail on a new zone** rather than on a fixed list of forbidden namespaces — the rule is
  "only these places", not "not these places", and only the former stays true as the package grows.

An architecture test rather than a custom PHPStan rule: equivalent guarantee, less machinery, and a
reviewer looking for *what may reference upstream* finds a test named for the invariant faster than
a rule class in the analyser config.

### 6. Contract tests name the consumer-side consequence — and say which are real-runtime

`tests/Contract/` pins the upstream behaviours Verdict depends on. What makes each a contract rather
than incidental coverage is that its docblock names *what Verdict does with it*, so a failure says
which Verdict guarantee lost its footing.

| Upstream behaviour | Why Verdict depends on it |
|---|---|
| `Approvable::shouldRequestApproval()` returning `Approval::required()` pauses the run | This pause *is* Verdict's confirmation gate |
| `Tool` / `Approvable` method signatures | `AbstractVerdictTool` and the capturing tools implement them |
| `ToolNameResolver` resolves the inner tool's advertised name | The model must see the wrapped tool's name, not the wrapper's |
| `ToolInvoked` carries an invocation id | Correlates evidence to a run |
| A two-turn resume mints two distinct invocation ids (#53/#218) | Shared ids would merge two runs' evidence |
| `AgentPrompt` field shapes (`invocationId`, `hasApprovalDecisions()`, `agent`) | Read directly by the adapter's listeners |
| The prompt-middleware pipeline actually invokes registered middleware | `VerdictProvenanceMiddleware` is how provenance is captured at all |
| The same `Agent` instance reaches middleware and the later `PromptingAgent` event | `PromptProvenanceRegistry` keys a `WeakMap` on it |
| Streaming response semantics (`StreamableAgentResponse` lazy iteration) | Gate ordering and evidence timing under streaming |
| `Decisions::all()` yields `toolCallId => Decision` with `isApproved()`, plus the `'*'` wildcard | The only upstream approval behaviour the kernel depends on, pre-translation |

Two of these are **not** currently proven against upstream's real runtime — agent identity across
the prompt lifecycle, and real middleware-pipeline invocation — and `docs/laravel-ai-compatibility.md`
says so explicitly, noting the identity test uses `Mockery::mock(Agent::class)` and therefore
sidesteps the question. The suite must **label each test real-runtime or hand-constructed**, because
a contract test built on a mock of the thing it is meant to pin proves only Verdict's own logic.

**The canary today runs `0.x-dev` only.** Adding the supported-range cell (`^0.11`) so the suite runs
as a matrix is part of this work, not an existing property. The canary keeps
`continue-on-error: true`: its value is signal, not a gate, which is already that workflow's stated
design.

#### Update — #340 contract catalogue

The initial table omitted three documented dependencies now covered by the contract suite:
`Contracts\Gateway\StepTextGateway`, which provides the controlled real-stream test seam;
`StreamableAgentResponse`'s protected Closure generator, which Verdict wraps by reflection; and the
`AgentResponse`/`StructuredAgentResponse`/`StreamableAgentResponse` taxonomy used by
`LiveAgentObserver`. The enforced catalogue is therefore thirteen behaviors, not ten.

### 7. What this buys, stated so it is not overread

While laravel/ai remains 0.x, this buys:

- **Kernel insulation** — the security core stops naming upstream types, so an upstream behaviour
  change cannot reach it except through a translation the adapter owns.
- **Bounded diagnosis** — integration changes are expected in the declared adapter zones and the
  composition root, and a named contract test fails instead of the suite going uniformly red.

It does not buy **prevention**, and no consumer-side structure can. It also does not confine every
break to one directory: two zones plus the provider may all need work for a single upstream change.
The honest claim is that the *kernel* is insulated and the *diagnosis* is bounded — not that
upstream churn stops costing anything.

This belongs in the ADR rather than only in the issue, because an adapter is exactly the kind of
artifact a reader assumes is stronger than it is.

## Alternatives rejected

### A single adapter zone, insulating `src/Evaluation/` too

Workable, but not worth it. It would require a harness-facing port with its own adapters, so that
`LiveAgentObserver` could classify runs — currently done by catching `ApprovalNotResumableException`
and inspecting `StreamableAgentResponse`/`AgentResponse`/`StructuredAgentResponse` — through Verdict
types, and `CapturingTool`/`UnguardedCapturingTool` could implement upstream tool contracts they
must implement to be passed to a real agent at all. That is *more* abstraction and a second taxonomy
mirroring upstream's, with no second harness consumer to keep the port honest, and any gap in the
re-derivation surfaces as a misclassified trial — an evaluation harness reporting the wrong outcome,
which is worse than the coupling removed. Revisit if a second harness backend ever exists.

### Inventing `ToolCallProposal` and `Correlation` now

Proposed by #324, rejected on the audit: nothing in the kernel reads `AgentPrompt`, and
`InvocationContext` already provides the correlation vocabulary with no upstream references. Two new
abstractions with one implementation each and no second caller to keep them honest.

### Moving `guard()`/`bound()` into the adapter

The design round's proposal, rejected in §4 on evidence: 63 direct callers against 4 facade callers,
and a parameter type the kernel never dereferences. A breaking change to the package's front door,
buying nominal purity and no containment.

### Enforcing the boundary with a custom PHPStan rule

Equivalent guarantee, more machinery, less legible. Revisit if the rule ever needs to distinguish
more than namespace membership.

### Waiting for a stability commitment from upstream

The endorsement conversation #324 describes is real, but every deliverable here is unilateral. Waiting
would leave the security path exposed for the duration of a negotiation that this work is meant to be
the argument for.

## Consequences

- An upstream break is absorbed in `src/LaravelAi/`, `src/Evaluation/`, or the provider, and is
  diagnosed by a named contract test. The kernel is not in the blast radius.
- The extracted kernel components — approvals, claims, evidence, rate limits, context, targets —
  become testable without upstream test doubles or runtime. Not the whole package: `VerdictManager`
  permanently names two upstream types at the seam, and `composer.json` still requires `laravel/ai`,
  so "testable without laravel/ai present" would be an overclaim.
- One new value object, one file move, one architecture test, one new test directory. The refactor is
  small; the rule that keeps it true is the durable part.
- Two contract behaviours are currently unproven against upstream's real runtime. Writing them is
  part of this work, and until they exist the compatibility document's caveat stands.
- `verdict-console` binds deeper than Verdict does (`RemembersConversations`, pause/resume,
  `ResumableAgents`). Hardening its adapter is that repo's work; the compatibility matrix (#324's
  third deliverable) is where the two are related, in `docs/laravel-ai-compatibility.md` beside the
  dependency inventory already there.
- Adding a third zone, or a third exception, is a deliberate act with a written reason — the
  property that makes this rule survive contact with the next upstream change.
