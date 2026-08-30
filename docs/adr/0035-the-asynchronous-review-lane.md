# ADR 0035: The asynchronous review lane — a durable review request, out of band and re-authorized

Status: Accepted

## Related issues

- [#297](https://github.com/fissible/verdict/issues/297) is the work this settles — the keystone of the
  approval-surface cluster.
- [ADR 0031](0031-approval-reads-are-observational-and-scoped.md) §6 reserved the review-request reads for
  this decision; §4 below designs what it deferred, and §5 inherits its expiry-is-computed rule.
- [ADR 0026](0026-what-an-approver-is-shown.md) — what a reviewer is shown is a context release; a review
  request carries the same disclosure on the same terms.
- [ADR 0003](0003-execution-target-freshness.md) — the validate-before, refresh, validate-after,
  consume-last ordering the review gate reuses verbatim.
- [ADR 0007](0007-evidence-layering.md) — pre-mutation propagation vs post-committed operational-state
  writes; review-request issuance is classified against it (§7).
- [#230](https://github.com/fissible/verdict/issues/230) is the confirmation-side twin of the silent-gate
  failure; [#306](https://github.com/fissible/verdict/issues/306) is the approver summary a request carries;
  [#201](https://github.com/fissible/verdict/issues/201), [#265](https://github.com/fissible/verdict/issues/265),
  and fissible/verdict-console ADR 0001 surfaced the gap.

## Context

Verdict has two human-actionable lanes. The **synchronous** lane, `RequireConfirmation`, pauses a live run:
`requestConfirmation()` issues an approval receipt (`src/VerdictManager.php:380-400`), the run holds, and a
decision resumes it. The **asynchronous** lane, `RequireReview`, is for a decision resolved *out of band* —
nothing is waiting on it.

The asynchronous lane has no substrate. `Disposition::RequireReview` exists
(`src/Decisions/Disposition.php:12`) and `Decision::requireReview()` produces it
(`src/Decisions/Decision.php:45-47`), but `run()` exits on any non-permit disposition
(`src/VerdictManager.php:198`) and `runBound()` excludes `RequireReview` from its admissible set
(`src/VerdictManager.php:209`). So a policy that returns `RequireReview` today is a **silent denial that
reads as "a human decides" while no human is ever involved** — the #230 failure mode in a second location.

The two lanes are **more alike at execution than at rest**, and that shapes everything below. The
confirmation gate already does exactly what a reviewed action needs: it derives an argument-bound fingerprint
that includes `approval_context` (`src/Approvals/ApprovalManager.php:217-230`), validates the receipt before
target refresh and again after it, then consumes it atomically after the remaining gates
(`src/VerdictManager.php:249,281,317`, the ADR 0003 order). What differs is the *rest* state: a receipt is
addressed for a run waiting **now** by `(toolCallId, bindingFingerprint)` with an atomic `consume()`
(`src/Contracts/ApprovalReceiptStore.php:58-70`); a review is addressed **later**, by its own id, by a
reviewer acting outside the request that proposed the action.

## Decision

### 1. Reserve loudly now; build the substrate in this milestone

Until the substrate lands, a **shared** `RequireReview` handling path — used by both `run()` and
`runBound()` — records the decision evidence and then throws a loud `RequireReviewNotImplemented`. This is a
change from today's *silent* denial (`src/VerdictManager.php:198,209`), and it is deliberately **not** a
registration-time check: an authorizer returns dispositions dynamically at decision time
(`src/VerdictManager.php:154-167`), so registration never sees the disposition. **Loud** here is a contract,
not only a thrown type: the refusal **names the capability it declined**, so the misconfiguration is
actionable from the error alone — the house exception convention (`CapabilityNotExecutable::named()`,
`UnsupportedApprovalDecision::editedArguments()`). The loud-reserve is the first buildable step; §3–§7 replace
the throw.

### 2. A separate primitive, sharing the gate — not a receipt reused

The `ReviewRequest` record and its store are **distinct from `ApprovalReceiptStore`**. The reason is not that
a discriminated status could not be added safely — it is that reuse would be an **undesirable compatibility
coupling**: a review is addressed differently (its own id, for a reviewer, with no live tool call waiting),
authorized differently (a decision maker acting out of band, §3), and admitted through a request lifecycle
the receipt store's `issue`/`consume` API does not model. What the two lanes **do** share is the
execution-gate mechanism (§5) and the re-authorization invariant (§6); they do not share the state machine.

### 3. The review-request record and its decision authorizer

A `ReviewRequest` is created when a decision resolves to `RequireReview`. It carries what is needed to
authorize a review out of band and to bind a later execution, and **nothing that is itself execution
authority**:

- an independently-addressable **review-request id** — the reviewer's unambiguous handle, since no live tool
  call waits;
- the **capability** and the **argument binding fingerprint** (`ApprovalManager::fingerprint()`'s form:
  capability, execution-target policy, arguments, application binding, and `approval_context` — the same
  material the confirmation lane binds, `src/Approvals/ApprovalManager.php:217-230`), so the reviewed
  artifact is argument-bound;
- the immutable **`approvalContext`** and the **resolution actor + timestamp** recorded at decision — a
  fail-closed **`ReviewDecisionAuthorizer`**, mirroring `ApprovalDecisionAuthorizer`
  (`src/Contracts/ApprovalDecisionAuthorizer.php`), keys on that context to establish that *this* human may
  resolve *this* request; provenance and the #306 summary are review *material*, and neither authorizes the
  reviewer nor binds the later attempt;
- the **provenance disclosure** (ADR 0026) and the **approver summary** (#306), materialized at issuance
  through the application's release route and recorded immutably with their fingerprints;
- `createdAt`, an optional application-set TTL surfaced as a **computed** `expiresAt` (ADR 0031 §5 — never a
  written status), and the lifecycle status (§6).

The record is **not a re-proposal token**; it describes an action and authorizes a *review* of it, never an
execution.

### 4. Reads: one discipline, a parallel typed reader (refining ADR 0031 §6)

Review reads share ADR 0031's discipline, exposed through a `ReviewStatusReader` over the review store —
DTO-returning (`ReviewStatusView`: request id, capability, status, reason, summary fingerprint, `createdAt`,
`expiresAt`, `resolvedBy`/`resolvedAt`, `approvalContext`), observational, enumeration **scoped or refused**
(`pendingWithin(non-empty scope)`), poll-consistent, expiry computed and never reported.

This **refines ADR 0031 §6**, which reserved review reads for "this same contract." ADR 0035 realizes that
as the same *discipline* through a **separate** typed reader rather than a widening of `ApprovalStatusReader`:
a review's DTO and its per-item key differ from a receipt's — the unambiguous per-item lookup is the
**request id**, not a tool-call id, because a review is out of band. Read ADR 0031 §6 as amended here.

### 5. The execution gate — a `ReviewManager`, and how a later run re-authorizes (load-bearing)

A granted review is **never** a standing authorization and **never** turns a `RequireReview` policy decision
into `Permit`. Admission runs through a dedicated **`ReviewManager`** gate — not `ApprovalManager`, whose
`validate()`/`consume()` reject capabilities that do not declare `confirmationRequired()`
(`src/Approvals/ApprovalManager.php:172`); the review lane keys on its own requirement. The `ReviewManager`
reuses the confirmation gate's structure in `runBound()`:

1. **Proposal stage.** A proposal-stage `RequireReview` decision is admitted into the bound pipeline exactly
   as `RequireConfirmation` is (`src/VerdictManager.php:244-249`), and the `ReviewManager` `validate()`s the
   request by its argument-bound fingerprint **before** target refresh.
2. **Execution stage.** Policy is re-evaluated and **must still return `RequireReview`** — the action
   inherently requires review, and Verdict never overrides that decision; the `ReviewManager` `validate()`s
   again **after** the refresh (`src/VerdictManager.php:281`).
3. **Ordering (the ADR 0003 sequence the confirmation gate uses, verbatim).** intent gate → rate-limit →
   **`consume()`** the review request (atomic, single-use) → execution-claim admission → execute. Consume
   runs after the intent and rate-limit gates and **before** the execution-claim gate, exactly where
   `approvals->consume()` runs (`src/VerdictManager.php:302,311,320,330`).
4. **Branch rule.** The executor proceeds on the **gate's** successful `consume()` — not on the policy
   disposition. An `Approved`, unexpired request whose binding matches admits **once**; `Pending`,
   `Rejected`, expired, absent, or binding-mismatched refuses. On a first attempt with no matching request,
   §7 issues one instead of admitting.

**Gate-admitted execution outcome — the seam that keeps the invariant implementable.** `RequireReview` by
itself does **not** permit execution. Unlike `RequireConfirmation` — admitted at the proposal stage
(`src/VerdictManager.php:213-216`), after which the confirmation gate rides a **`Permit`** execution decision
plus the always-present receipt gate — `RequireReview` is the authorizer's own decision, returned at the
execution stage as well, and `permitsExecution()` is true only for `Permit`
(`src/Decisions/Decision.php:58-60`). A `RequireReview` execution decision therefore cannot pass the execution
boundaries, and *making* it permit would fail **open** for any capability whose authorizer returned it with no
review gate wired. So a bare `RequireReview` with no admissible review must refuse or issue (§7), **never**
execute; admission cannot ride the policy disposition.

Instead, a successful `consume()` (§5.3) yields a **distinct review-admitted execution evaluation** — an
outcome that `permitsExecution()` for the downstream boundaries — while the **recorded decision stays
`RequireReview`**, carrying the review-request id. Evidence therefore reads "policy required review; a
consumed review admitted this execution," never a rewritten `Permit`. Three `permitsExecution()` boundaries
consult the admitted outcome rather than the policy result: the post-refresh execution-stage gate
(`src/VerdictManager.php:274`), the execution-claim admission in `executeAfterRateLimit()`, and
`AuthorizedAction` (`src/Actions/AuthorizedAction.php:27`). Verdict never reinterprets `RequireReview` as
`Permit`; the admitted outcome is a separate authority derived from the consumed review artifact.

Because admission flows through the gate and never through the policy decision, the re-authorization
invariant holds by construction — a fresh policy decision, a target refresh, and fresh argument-bound,
single-use review validation are all required, and approval alone never permits execution (the rule
verdict-console ADR 0001 makes for the console UI).

### 6. Lifecycle, single-use, and idempotency

```
Pending ──approve──▶ Approved ──consume──▶ Consumed
   └────reject──▶ Rejected
```

`Approved` and `Rejected` are terminal review **decisions**; `Consumed` is the terminal **lifecycle** state —
the single, atomic execution admission of an `Approved` request (§5.3). Expiry is checked at **decision** and
at **consumption**, computed from `expiresAt` (never written, ADR 0031 §5). Issuance is **idempotent per
binding** — re-issuing the same `(capability, arguments, approval_context, …)` binding returns the existing
request, as the receipt store's `issue()` does. A `Rejected` or expired request is **not** reissued: a
changed proposal is a different binding and a new request; the same binding re-presented after rejection
stays refused.

### 7. Entry points, issuance, and failure — fail-closed

The two entry points have different authority, and only one can admit:

- **`run()`** evaluates and executes an arbitrary callback with **no** target-refresh or gate pipeline
  (`src/VerdictManager.php:198-206`). It therefore **issues a `ReviewRequest` and refuses** — it can never
  *admit* a reviewed execution.
- **`runBound()`** is the **sole** reviewed-admission path (§5). Review issuance occurs **at attempted
  execution** in `runBound()`, when the execution-stage decision is `RequireReview` and no matching
  admissible request exists — **not** at the confirmation preflight
  (`AbstractVerdictTool::shouldRequestApproval()` → `requestConfirmation()`), which serves the *pausing*
  confirmation lane. The model receives a structured **`review-pending` refusal**, never a pause.

**Ordering and failure.** Evaluate and record the decision (pre-mutation propagation, ADR 0007), then
**issue** the durable request — a post-committed operational-state write correlated to that evidence, the way
`requestConfirmation()` calls `approvals->issue()` and lets failure propagate
(`src/VerdictManager.php:400`) — then return the `review-pending` refusal. Issuance failure **propagates and
refuses**; it is **never** translated into a normal denial that hides a failed request.

Until the substrate lands, both entry points take the §1 shared loud-reserve path.

## Alternatives rejected

**Reuse `ApprovalReceiptStore` with a new status or kind.** A discriminated kind could be made safe, so the
objection is coupling, not impossibility (§2): it would tie an out-of-band, id-addressed review lifecycle to
an API built for an immediately-waiting run keyed by tool call.

**A review that yields a "reviewed, let it through" standing grant.** Rejected: that is an
authorization-bypass channel. Admission flows through the gate (§5), never through the policy decision.

**Turning a re-attempted `RequireReview` into `Permit` when an approved review exists.** Rejected for the
same reason: it would override a fresh policy decision. The policy stays `RequireReview`; the review gate is
what admits.

**Reject `RequireReview` at registration.** Rejected: dispositions are produced dynamically by the
authorizer, not declared at registration (§1).

**A synchronous review (a live run waits).** Rejected: that is `RequireConfirmation`.

**A second parallel read surface, or widening `ApprovalStatusReader`.** Rejected: review reads are a separate
typed reader over a separate store, sharing one discipline (§4, ADR 0031 §6).

## Consequences

- verdict-console's asynchronous review lane becomes buildable: a durable `ReviewRequest`, a fail-closed
  `ReviewDecisionAuthorizer`, an out-of-band decision, and a `ReviewStatusReader` of the ADR-0031 shape.
- The review gate reuses the confirmation gate's validate/refresh/validate/consume ordering (ADR 0003)
  verbatim, so neither lane can admit an action that would not pass every gate on its own.
- The loud-reserve (§1) ships first and closes the silent denial immediately; §3–§7 are the larger follow-on
  within the same milestone.
- #306's approver summary and #201's lineage compose onto the review-request record rather than arriving as
  separate surfaces; ADR 0031 §6's reservation is discharged here.

**What this does not do.** It does not resume a *streamed* reviewed action (ADR 0006, deferred); it does not
build the console UI (verdict-console ADR 0001); and a granted review confers no standing authority — every
execution re-earns admission.
