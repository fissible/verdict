# ADR 0040 — Refusing an edited approval is the correct answer; the edited action is re-proposed, not rebound

- Status: Proposed
- Deciders: Verdict maintainers
- Issue: #419

## Context

Laravel AI supports **approve-with-edits**: `Decision::edit([...])` carries modified arguments, and on
resume the framework executes the tool with them (`$arguments = $decision->arguments ?? $toolCall->arguments`,
`TextGenerationLoop::resolveApprovalResults()`).

Verdict refuses this at the adapter boundary today: `LaravelApprovalDecisions::approvedToolCalls()`
throws `UnsupportedApprovalDecision::editedArguments()` on any `Decision::isEdited()` (#396 / PR #418).
This ADR asked whether Verdict should instead *safely support* an edited approval if adopter demand
warrants. The answer, after establishing what the resume model can and cannot do: **the refusal is
correct and remains.** The supported way to perform an edited action is to re-propose it, not to rebind
an approval to it.

## Why not a fingerprint rebind

Verdict authorizes a *resolved target*, not a tool. `resolveTarget` derives the target from the
arguments; `CapabilityAuthorizer::decide()` authorizes the actor against *that* target. Editing the
arguments can change the target — `cancel order 1001` (customer 72, authorized, receipt bound
`ArgumentFingerprint(1001)`) edited to `cancel order 1002`, which may belong to a **different customer
and was never authorized**. Rebinding the receipt to `ArgumentFingerprint(1002)` without re-authorizing
would authorize an action the actor was never allowed to take — an IDOR / privilege escalation, and,
because the edit rides inside the decision, a swap-past-authorization primitive for anyone who can
influence the approval decision. Strictly worse than refusing.

## The mechanical finding that decides it

The natural safe alternative — treat the edit as a new proposal and issue a **fresh challenge**
(re-resolve the target, re-run authorization, re-disclose per ADR 0026, require a new approval) — was
the maintainer's initial preference. It is **not implementable inline** in Laravel AI's resume model.
Verified against `vendor/laravel/ai/src/Gateway/TextGenerationLoop.php`:

- On resume, `resolveApprovalResults()` takes each non-rejected decision, computes the (edited)
  `$arguments`, and calls `executeTool(...)` **directly**, recording whatever *string* the tool returns
  as a `ToolResult`. A tool cannot return an `Approval` from execution, and a thrown exception is
  captured as a failed tool result — not a pause.
- The **only** approval-pause transition is `approvalAwareToolResults()` → `PendingApproval`, and it
  fires only on a **forward step** for a model-emitted tool call — never while resolving an
  already-pending call during a resume.

So at resume there is no way to obtain a new approval for the edited call: nothing in the resume path
**can create a Laravel AI approval pause**. (Today Verdict's adapter throws on the edited decision
*before* the tool runs; but even if that throw were removed, resolving the edited call could only
execute it or record a string/failed result — never re-pause.) A fresh challenge for the edited target
is reachable **only** by the edited action being **re-proposed as a new tool call** on a subsequent
forward step — which already runs the full pipeline: `resolveTarget` → `CapabilityAuthorizer` →
ADR 0026 disclosure → `requestConfirmation` → a new receipt bound to the edited arguments → a human
approval of the action they can actually see.

## This ADR supersedes the "fresh inline challenge" idea

An earlier turn of this design proposed that an edited approval re-enter the pipeline and receive a
**fresh inline challenge** (re-disclosure + new approval) during the same resume. This ADR explicitly
supersedes that idea. It remains a **desired security property** — an edited target must not be
authorized without its own disclosure and consent — but it is **not a representable Laravel AI resume
transition**: the resume path executes or refuses the pending call and offers no pause (the mechanical
finding above). The property is therefore delivered by re-proposal, and the inline formulation is
withdrawn as unrepresentable rather than merely deferred.

## Decision

**The adapter continues to throw `UnsupportedApprovalDecision` on any edited decision.** This is the
design of record, not a placeholder. A human edit is not an approval of a fresh target's disclosure, and
Laravel AI provides no mechanism to obtain that disclosure or consent inside the same resume.

The security guarantee #419 exists to protect — *an edited target is never authorized on the strength of
a typed identifier* — is fully preserved, and is delivered by the **re-proposal path**, not by any
inline rebind or re-challenge:

- Verdict refuses the edited decision; the original receipt's state is **left unchanged** (an edited
  resume typically follows an approval, so it is usually `Approved`) and **unconsumed**, and it is
  **never rebound**.
- To carry out the edited action, the agent re-emits it as a **new tool call with a new call id**. That
  new proposal is resolved, authorized, disclosed, and challenged from scratch — the approver sees the
  edited action's own material facts, and an unauthorized edited target is denied there, before any
  receipt exists. (A genuinely new call id yields an independent receipt/binding; a *reused* provider id
  would fall into the collision behaviour Verdict already models under #425, so the re-proposal must be
  a new call, not a re-decision of the old one.)

Verdict cannot *make* the model re-propose; that is the agent loop's behaviour. Verdict's contract is
the two halves it does own: refuse the unsafe inline edit, and guarantee that any re-proposed action is
fully evaluated and challenged on its own terms.

**Recommended (non-blocking) refinement:** the `UnsupportedApprovalDecision` message currently says
"resume with `Decision::approve()` for the original proposal." It should also name the re-proposal path,
so an integrator who genuinely wants the edited action knows the supported route rather than reading the
refusal as a dead end.

## Alternatives rejected

- **Fingerprint rebind** — IDOR / swap-past-authorization (above).
- **Inline fresh challenge on the edited call** — mechanically impossible: Laravel AI's resume path
  cannot issue an approval pause while resolving an already-pending call (the mechanical finding above).
- **Narrow inline support for edits that change neither the binding nor the disclosed facts** — the only
  edits this could admit change nothing Verdict binds or discloses, so they are near-no-ops; and the
  disclosure half is not even decidable from persisted state. `runBound()` already fail-closes a
  binding-changing edit — its exact `(tool_call_id, binding_fingerprint)` lookup finds no matching
  approved receipt for the edited arguments, so none can validate or be consumed; there is no persisted
  "disclosed-material-facts fingerprint"
  (ADR 0026 releases source/trust/data-class/channel; ADR 0038's summary fingerprint proves the snapshot,
  not that the prose covers every bound fact), so an equality test for the disclosure dimension would
  require new persistence and a defined algorithm to buy a marginal case. Not worth building.
- **Async out-of-band re-challenge (ADR 0035 review-lane)** — could mint a real new challenge for the
  edited target decoupled from the generation loop, but is a substantial mechanism to couple into #419
  for a demand-gated feature with no adopter asking. If a concrete adopter need appears, this is the
  door to reopen — as a review-lane integration, not an inline resume change.

## Why refusing does not lose the guarantee

The consent model the maintainer chose (never honour a typed edited identifier without the edited
action's own authorization *and* its own ADR 0026 disclosure *and* its own approval) is satisfied
exactly by the re-proposal path: every one of those three checks runs on the re-proposed forward pass.
What refusing "loses" is only the *inline UX* of honouring the human's edit within the same resume turn
— which the framework cannot make safe anyway, because it offers no pause there. Refusing is therefore
not a lesser substitute for the chosen model; within this resume model it is the only implementation of
it.

## Consequences

- No kernel change, no new receipt state, no adapter `EditedProposal` value, no capability opt-in, no
  interaction with the ADR 0039 binding-admission critical section — every complication a
  rebind/supersede design would have introduced is avoided by refusing.
- The `UnsupportedApprovalDecision` message may be clarified to name the re-proposal path (a docs/UX
  refinement, not a contract change).
- `docs/` gains a short statement of the supported flow for an edited action (re-propose), so adopters
  do not read the refusal as "edits are impossible" when they mean "edits are re-proposed."
- #419 closes: the design question is answered. If an adopter later presents a concrete need for an
  inline-feeling edit flow, the async review-lane door (above) is where it reopens, on its own issue.

## Acceptance (already met by the current throw, pinned so it cannot regress)

- Any `Decision::isEdited()` for a Verdict-bound tool is refused; no edited arguments are ever executed
  against a receipt bound to different arguments (even if the adapter throw were bypassed, `runBound()`'s
  exact `(tool_call_id, binding_fingerprint)` lookup finds no matching approved receipt for the edited
  arguments, so none can validate or be consumed — the fail-closed backstop).
- A re-proposed edited action is authorized, disclosed, and challenged from scratch — an edit to an
  unauthorized target is denied at authorization, before any receipt exists.
- No path rebinds an existing receipt to edited arguments.
