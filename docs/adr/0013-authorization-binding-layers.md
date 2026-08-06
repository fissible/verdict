# ADR 0013: Identity binding, authorization-request binding, and runtime execution binding are distinct

Status: Accepted

## Related issues

- [#19](https://github.com/fissible/verdict/issues/19) (open) documents the accepted security-state
  ordering; it is the natural home for the invariant stated below.
- [#20](https://github.com/fissible/verdict/issues/20) (open) adds genuine concurrency tests, which is
  where the invariant becomes executable rather than documentary.
- [#31](https://github.com/fissible/verdict/issues/31) (open) adds actor and subject identity to
  `DecisionEvidence`, closing the gap this ADR names in the first binding layer.

## Context

Verdict's guarantees are currently described one mechanism at a time: approval receipts, execution
claims, semantic rate limits, execution-target policies. Nothing in the documentation says *which
security property* each mechanism serves. That has two costs. Mechanisms that serve the same
property read as redundant to a newcomer, and a property Verdict does not provide reads as a missing
field rather than a missing guarantee.

The literature supplies a vocabulary that fits Verdict almost component for component.

Llambí-Morillas and Fernández-Fernández formalize authorization for autonomous agents as a relation
binding "an agent principal, a concrete authorization request, an execution context, and the
satisfaction of an applicable policy," and state its central structural separation as:

> Identity Binding ≢ Authorization Request Binding ≢ Runtime Execution Binding
>
> A system may correctly authenticate an agent yet fail to bind authorization evidence to a specific
> request. It may likewise bind evidence to a request without guaranteeing that the same request is
> executed at runtime. These are structurally distinct security properties requiring separate
> mechanisms.

They also observe that neither authentication nor delegation implies authorization —
`Delegate(U, Ai, κ) ⇏ AuthZ(qi)` — because "delegation may establish authority over a broad class of
operations, while the permissibility of a concrete request may still depend on the target resource,
the execution context, the applicable policy version, and temporal validity."

The third layer is the one their construction cannot reach. Their Table 2 lists runtime execution
binding as **Open**, mechanism: "No trusted execution evidence." The reason is structural rather than
incidental: a zero-knowledge circuit "is evaluated at proof generation time over a committed
representation of the intended request; it has no access to the runtime state at execution time and
cannot constrain what the agent subsequently does with the authorization it receives." Their
statement of the resulting exposure is the general form of TOCTOU: if verification occurs at `tv` and
execution at `te > tv`, then `ctv ≠ cte` is possible, therefore
`Authorized(q, ctv) = 1 ⇏ Authorized(q, cte) = 1`. Closing it, they conclude, requires "a trust anchor
that operates at execution time rather than at proof generation time."

Verdict has that trust anchor, because it is an in-process library rather than a gateway. It refreshes
the execution target and then re-runs authorization against the refreshed value before entering the
executor:

```php
// src/VerdictManager.php:182
$refreshEvaluation = $this->refreshTarget($proposalEvaluation, $targetPolicy);

// src/VerdictManager.php:192
decision: $this->authorizer->decide($capability, $envelope, $refreshEvaluation->target),
```

Three unrelated sources demand exactly this property:

- **Zanzibar's new enemy problem**, Example B — misapplying an old ACL to new content — is prevented
  only by ensuring the authorization decision is not staler than the state it authorizes.
- **NIST SP 800-207** tenet 6 enumerates reauthorization triggers as "time-based, new resource
  requested, **resource modification**, anomalous subject activity detected." Resource modification is
  precisely what a target refresh detects.
- **The CVA triad** above, where the absence of this mechanism is stated as an open research problem.

The problem is that in Verdict the property exists **only as the order of statements in one method**.
ADR 0003's "Required execution order" states it as a fourteen-step behavioral list. Reorder those
statements — reuse the proposal-stage decision, move the claim binding above the refresh, pass the
proposal target to the executor — and a security property disappears with no failing test.

Lamport, annotating *On-the-fly Garbage Collection*, states the lesson directly: "behavioral proofs
are unreliable and one should always use state-based reasoning for concurrent algorithms." A sequence
of statements is a behavioral argument. A predicate over states is something a reviewer or a test can
check.

## Decision

### 1. Adopt the three-layer vocabulary

Verdict's documentation uses these three terms, and each Verdict mechanism is described as serving
one of them.

| Layer | Question it answers | Verdict mechanism | Grounding |
|---|---|---|---|
| **Identity binding** | Which principal is acting? | `ActionContext::$actor`, passed to the Laravel gate | `src/Actions/ActionContext.php:12-15`, `src/VerdictManager.php:104` |
| **Authorization-request binding** | Which concrete request was authorized? | Argument fingerprint and idempotency key; approval binding fingerprint over capability, target-policy name, arguments, and application-supplied binding facts | `src/Evidence/DecisionEvidence.php:18-19`, `src/Approvals/ApprovalManager.php:147-161` |
| **Runtime execution binding** | Did the request that was authorized actually run? | Target refresh, canonical identity comparison, re-authorization at the refreshed target, execution-stage approval revalidation, and rate-limit and execution-claim bindings all derived from the execution evaluation | `src/VerdictManager.php:182`, `:192`, `:200-206`, `src/ExecutionClaims/ExecutionClaimManager.php:37-41` |

The layers are ordered by how much they cost to provide and by how rarely they are provided. Verdict's
distinctive contribution is the third column, not the first.

### 2. State re-authorization as an invariant, not an ordering

**Invariant B1.** *No execution-stage decision, approval transition, rate-limit consumption, or
execution-claim admission may be derived from a target snapshot older than the refresh performed for
that envelope.*

This is the state-based restatement of ADR 0003's steps 6 through 13. ADR 0003 remains the normative
description of the order; this ADR states the property that order exists to produce, so that a change
to the order can be evaluated against something other than the previous order.

Invariant B1 is a required property of `runBound()`. Any change to `VerdictManager::runBound()`,
`ApprovalManager`, `ExecutionClaimManager`, or `RateLimitManager` that cannot be shown to preserve it
is a security regression regardless of whether the test suite passes.

### 3. Record why Verdict can provide the third layer

Verdict provides runtime execution binding **architecturally rather than cryptographically**, because
it executes in the same process as the executor and therefore holds the execution-time trust anchor a
verifying gateway does not have. This is the substantive argument for Verdict's deployment model, and
it should be stated as such rather than left as a convenience claim.

The claim is bounded, and the bound is not negotiable:

- Verdict narrows the window between authorization and execution and refuses to execute when the
  target's canonical identity changed inside it. It does not make the window zero, and it does not
  make a mutable database immutable. This is already stated in
  [limitations: no complete TOCTOU protection](../limitations.md#no-complete-toctou-protection) and
  ADR 0003, and nothing here weakens it.
- Binding holds at the granularity of the application-supplied canonical identity and binding facts,
  not of full resource state. A change the application chose not to include in either is invisible to
  Verdict by construction (ADR 0003, "Canonical identity").
- The anchor is the process, so it is only as trustworthy as the process. Verdict makes no claim
  against a compromised application host.

### 4. Name the gap in the first layer

Identity binding is Verdict's weakest column. `ActionContext` carries a single `mixed $actor`
(`src/Actions/ActionContext.php:12-15`), and `DecisionEvidence` has twenty-six fields, none of which
identifies the actor (`src/Evidence/DecisionEvidence.php:12-39`). Verdict authorizes against an
identity it does not record.

Stated inside this taxonomy, that is not a missing field. It is a security property Verdict enforces
at decision time and cannot demonstrate afterwards: evidence can prove which request was authorized
and that the authorized request is the one that ran, but not *for whom*. Closing it is tracked
separately.

## Non-goals

- **No `src/` change is made or required by this ADR.** It states a property the code already has and
  names one it does not.
- **No cryptographic verification.** See "Alternatives rejected."
- **No delegation model.** "Which principal is acting" becomes materially harder when an orchestrator
  spawns sub-agents. That is a separate decision and is not settled here.
- **This does not amend ADR 0003.** The required execution order stands unchanged.
- **No renaming.** `ExecutionTargetPolicy`, `ApprovalReceipt`, and `ExecutionClaim` keep their names.

## Consequences

- Documentation gains a vocabulary for describing what each mechanism is for, and a place to say
  honestly that Verdict provides the third layer well, the second layer well, and the first layer
  incompletely.
- Invariant B1 gives a reviewer a property to check a refactor against, and issue #20's concurrency
  work gains a specific assertion target: an execution-stage decision derived from a pre-refresh
  snapshot must be observable as a test failure.
- The absence of actor identity in evidence is reclassified from a nice-to-have audit field to a hole
  in a named security layer, which is the correct priority for it.
- Verdict acquires a defensible answer to "why not put this behind a gateway": a gateway cannot
  provide runtime execution binding, and the paper that formalizes the property says so.

## Alternatives rejected

### Adopt cryptographic proofs of authorization (zk-SNARK, remote attestation, or a TEE)

The paper's own construction exists because a verifying gateway lacks an execution-time trust anchor.
Verdict has one. Adding proof generation would impose meaningful cost to obtain a property Verdict
already holds by a cheaper mechanism, and would not extend to the layer that remains genuinely open
for Verdict — identity binding across delegation hops, which no proof system closes either.

Remote attestation and TEEs answer "is the host trustworthy," which is outside Verdict's boundary
(ADR 0004) and is an infrastructure decision the application owns.

### Treat ADR 0003's fourteen-step order as sufficient

It is a behavioral argument, and Lamport's objection applies directly: correctness that lives in the
order of statements can be broken by a refactor that looks locally reasonable. The ordering is
necessary and remains normative; it is not sufficient as the *statement* of the property.

### Fold this into ADR 0003 as an amendment

ADR 0003 is an API-design decision about execution-target freshness. The binding taxonomy is broader
— it also describes `VerdictManager::run()`, approvals, and the provenance surface — and it is used to
classify a gap ADR 0003 has no reason to discuss. Keeping it separate lets ADR 0003 stay a design
document and this stay a vocabulary and an invariant.

### Rename Verdict's components to the paper's terms

The paper's terms name *properties*; Verdict's names name *mechanisms*. Both are useful and they are
not in competition. Renaming would churn a public API for no gain, and would obscure the fact that a
single mechanism (the execution-target policy) contributes to more than one property.

### Claim runtime execution binding without stating its bounds

Rejected explicitly, because the claim is strong enough to be worth overstating. The bounds in
Decision §3 are part of the decision, not commentary on it. Any documentation that cites this ADR
must carry them.

## Sources

- Llambí-Morillas, S. and Fernández-Fernández, D. "Toward Cryptographically Verifiable Authorization
  for Autonomous AI Agents." arXiv:2607.21325v1 [cs.CR], July 2026.
- Pang, R. et al. "Zanzibar: Google's Consistent, Global Authorization System." USENIX ATC '19.
- NIST Special Publication 800-207, *Zero Trust Architecture*, August 2020, tenets 3 and 6.
- Lamport, L. Author's annotation to "On-the-fly Garbage Collection: An Exercise in Cooperation"
  (1978), on his publications page.
