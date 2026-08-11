# ADR 0015: Authority propagation — delegation attenuates; escalation is a separate principal

Status: Accepted

## Related issues

- [#31](https://github.com/fissible/verdict/issues/31) (open) adds a subject/actor split to
  `ActionContext` and records actor identity in `DecisionEvidence`. It is the only implementation work
  this ADR implies, and it is the prerequisite for either mechanism below being *demonstrable* after the
  fact.
- [#15](https://github.com/fissible/verdict/issues/15) (implemented by [#73](https://github.com/fissible/verdict/pull/73))
  makes `GuardedTool` usage observable in evidence. It closes the adapter-primitive part of the
  evidence-visibility gap; #31 remains necessary to make actor identity demonstrable after the fact.

## Context

Two structurally different things get described with the same sentence: "the agent was able to do more
than the user could." Conflating them is the most consequential modeling error available in this area,
because the two mechanisms have *opposite* invariants.

**Delegation** is a principal passing a subset of its own authority to another. The literature is
unanimous on the invariant: delegation **attenuates**. RFC 8693 encodes the acting party as a nested
`act` claim precisely so that each hop is visible and no hop can widen scope. SPIFFE/SPIRE bind
workload identity so a delegate is identifiable rather than anonymous. Miller's revocable forwarder is
an attenuating wrapper by construction. SSRN 6439998 calls "on behalf of" delegation the single
highest-impact unsolved deliverable for agent systems.

**Escalation** is not delegation at all. The authority does not come from the user, and it does not
come from the approver either. `sudo` does not grant you the authority of whoever is in the sudoers
file; it grants you *root's* authority, conditioned on your eligibility. When a support agent issues a
refund above their own limit after a manager confirms it, the agent is not exercising the manager's
authority — the agent is exercising the **business's** authority, with managerial confirmation as an
*input to the policy* that authorizes it. This is the trusted-subsystem pattern, and the caretaker
pattern in the object-capability literature is its analogue.

The dangerous version of the error is modeling escalation as delegation. If "the manager approved
this refund" is recorded as "the manager delegated to the agent," then a system that *correctly*
enforces attenuation will permit the agent everything the manager could do — which is enormously more
than the one refund that was actually approved. The attenuation invariant is not violated; it is
satisfied against the wrong bound.

Verdict currently expresses neither mechanism in its identity model. `ActionContext` carries a single
actor:

```php
// src/Actions/ActionContext.php
final readonly class ActionContext
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public mixed $actor,
        public array $metadata = [],
    ) {}
}
```

There is no chain, no parent, no subject. `DecisionEvidence` has twenty-six fields and none of them
identifies the actor (`src/Evidence/DecisionEvidence.php:12-39`).

But Verdict *does* already implement the escalation mechanism, correctly, without naming it.

## Decision

### 1. Verdict distinguishes the two mechanisms and names them

Delegation and escalation are separate concepts with separate invariants. Verdict's documentation,
source, and future design work use the two terms distinctly and never as synonyms.

### 2. Delegation attenuates — stated now as a constraint on future work

**Invariant D1.** *If Verdict ever models multi-hop agent identity, the effective authority at hop `n`
must be a subset of the effective authority at hop `n−1`. No hop may add authority, broaden scope, or
extend validity.*

Verdict does not model multi-hop identity today, and this ADR does not add it. The invariant is stated
in advance so that a future delegation model cannot be introduced without it — this is exactly the
kind of property that is easy to omit and expensive to retrofit, and every source above independently
identifies it as the property that matters.

### 3. Escalation is a separate principal, and Verdict already expresses it

Elevated approval is **not** a second source of authority. It is an additional condition on the
business's own authority. Verdict's existing composition says this precisely:

```php
Capability::usingPolicy('orders.refund', 'refund', $resolveOrder)
    ->requiresConfirmation($refundApprovalBinding, 'Refund exceeds agent limit')
    ->atMostOnce($claimPolicy)
    ->executeUsing($issueRefund);
```

- The Laravel gate decides against the **business's** rules, not the approver's
  (`src/VerdictManager.php:104`).
- `requiresConfirmation()` (`src/Capabilities/Capability.php:101`) binds the approval to one concrete
  request, via a fingerprint over capability, target-policy name, arguments, and
  application-supplied binding facts (`src/Approvals/ApprovalManager.php:147-161`).
- `atMostOnce()` (`src/Capabilities/Capability.php:145`) bounds the escalated authority to a single
  execution.

The approver's identity (`approvedBy`) records **who satisfied the condition**, not **whose authority
was used**. That distinction is normative. Documentation must not describe `approvedBy` as a delegator,
and a future feature must not derive the actor's effective permissions from the approver's.

The attenuation invariant does not apply here, because nothing was attenuated — a different principal's
authority was conditionally exercised, bounded by the approval binding and the claim rather than by a
subset relation.

### 4. Naming rule

- "Delegation" is reserved for attenuating propagation of a principal's own authority.
- "Escalation" or "elevated approval" names the trusted-subsystem case.
- `approvedBy` is a condition-satisfier, never a delegator.

These entries go in `docs/glossary.md`.

### 5. Name the gap

Verdict can currently *enforce* escalation but cannot *demonstrate* it. Evidence records that an
approval receipt was consumed and which phase it was validated in, but not who acted and not on whose
behalf. RFC 8693's subject/actor split is the minimal shape that fixes both: a **subject** (on whose
behalf the action is taken) and an **actor** (who is acting). That is tracked as implementation work,
not decided here beyond the shape.

## Non-goals

- **No `src/` change is made by this ADR.**
- **No multi-hop identity model.** Invariant D1 constrains such a model; it does not introduce one.
- **Verdict does not authenticate `approvedBy`.** Already true and already documented
  ([architecture: resolving an approval](../architecture.md#resolving-an-approval)); this ADR does not
  change it.
- **No token format.** See "Alternatives rejected."

## Consequences

- The escalation mechanism Verdict already ships acquires a name and a written rationale, so it stops
  reading as "approvals" — a UX feature — and starts reading as what it is, an authority-propagation
  model.
- A contributor proposing a delegation model inherits Invariant D1 as a requirement rather than
  discovering it.
- The absence of actor identity in evidence is reclassified, consistently with ADR 0013 §4, from a
  missing audit field to the reason a mechanism Verdict enforces cannot be shown to have been enforced.
- Documentation that currently describes approvals purely procedurally gains the sentence that matters:
  the approver is not lending authority.

## Alternatives rejected

### Model elevated approval as delegation from the approver

Rejected as the central error this ADR exists to prevent. It drops the bound that actually applies —
one request, one execution — and substitutes a far wider one: everything the approver could do. It also
misrepresents accountability, implying the approver authorized a class of actions rather than one.

### Defer the entire question, delegation and escalation together

This was the original shape of this ADR, and it is wrong. Half the question is already answered in
shipped code. Deferring it leaves a working mechanism undocumented, which invites a contributor to
build a delegation model to solve a problem escalation already solves — the exact conflation described
above.

### Adopt RFC 8693 token exchange as Verdict's identity model now

Rejected for now. Verdict's actor is `mixed` and application-owned by design; requiring exchanged JWTs
would import an authentication model Verdict deliberately does not own (ADR 0004) and would break every
application that passes an Eloquent user. RFC 8693's *structure* — the subject/actor split and nested
`act` claims — is adopted as the shape to follow; its transport is not.

### Add a `delegatedFrom` field to `ActionContext` as a first step

Rejected because the name encodes the error. If a single field is added first it must be the
subject/actor split, which is neutral between the two mechanisms and correct for both.

## Sources

- Jones, M., Nadalin, A., Campbell, B., Bradley, J. and Mortimore, C. "OAuth 2.0 Token Exchange."
  RFC 8693, January 2020 — `may_act`/`act` claims and delegation semantics.
- Miller, M. S. *Robust Composition*, 2006 — revocable forwarders, the caretaker pattern.
- SPIFFE/SPIRE workload identity model — identifiable, non-anonymous delegates.
- SSRN 6439998 — "on behalf of" delegation as the highest-impact open deliverable.
- Saltzer, J. H. and Schroeder, M. D. "The Protection of Information in Computer Systems." 1975 —
  least privilege as the source of the attenuation requirement.
