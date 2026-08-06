# ADR 0014: A Verdict `Capability` is a named permission, not an object capability

Status: Accepted

## Related issues

- A follow-up issue adds `docs/glossary.md`, where the naming rule in Decision §4 becomes
  enforceable rather than advisory.
- [#17](https://github.com/fissible/verdict/issues/17) (open) audits public extension-contract
  stability; `Capability` is the largest surface in that audit and its intended semantics belong in
  writing before the audit fixes them.

## Context

Verdict's central class is called `Capability`. That word has a precise, well-defended meaning in
security literature that is **not** the meaning Verdict uses, and the mismatch produces a recurring
category error: reviewers assume Verdict inherits guarantees it does not have, and contributors
propose changes that would only make sense in the other model.

In the object-capability tradition — Dennis and Van Horn (1966), Hardy's confused deputy (1988),
Miller's *Robust Composition* (2006), and the KeyKOS/EROS/E lineage — a capability is an unforgeable
reference that **is** the authority. The slogan is *designation is authorization*: naming the object
and being permitted to act on it are the same act, because the only way to name it is to hold a
reference that was handed to you. There is no separate permission check, and there is no ambient
authority to consult.

Verdict is the inverse, deliberately:

```php
Capability::usingPolicy('orders.refund', 'refund', $resolveOrder)
```

A Verdict capability is a registry entry (`src/Capabilities/CapabilityRegistry.php:17`, `:33`)
naming a Laravel authorization ability, a trusted target resolver, optional safeguards, and an
executor. The model designates by **string name**. The application resolves the target from its own
data (`src/Capabilities/Capability.php:72`) and asks a Laravel gate whether the actor may act on the
resolved object. Designation and authorization are separate on purpose — separating them *is* the
product. "Models propose. Applications authorize" is precisely the denial of "designation is
authorization."

Verdict also runs inside an ordinary Laravel process with full ambient authority. The executor
closure can reach the database, the filesystem, and the network. Verdict does not remove ambient
authority; it puts a gate in front of one named entry point to it.

Where the two traditions do meet is the **confused deputy**. Hardy's compiler held authority its
caller lacked and was tricked into using it by a caller-supplied filename. That is structurally
identical to prompt injection through a tool: the agent holds the application's authority, and the
attacker supplies the argument. The traditions diverge on the remedy. Object capabilities answer by
passing an unforgeable reference instead of a name. Verdict answers by never treating a model-supplied
designator as a resolved object: the name is re-resolved through application-trusted code, and the
decision is made against the resolved object and the application's own actor.

Both are valid answers. Only one of them is Verdict's, and the documentation has never said which.

## Decision

### 1. `Capability` means named permission

A Verdict capability is a **named permission**: a registered entry that binds a name to an ability,
a trusted resolver, safeguards, and an executor. It is not an object capability, confers no authority
by possession, and must not be documented, marketed, or reasoned about as one.

This use of the word is ordinary outside the object-capability literature — Linux `capabilities(7)`
names permissions the same way — so the name stays.

### 2. Verdict's security argument is deputy discipline, not object capability

State the argument in its own terms rather than borrowing one that does not apply:

- The untrusted principal supplies **designators only**, never authority.
- Every designator is re-resolved through application-trusted code before any authorization decision
  is made (`src/Capabilities/Capability.php:72`).
- The decision is made against the **resolved object** and the application's actor, not against the
  model's description of either.
- The resolved object is re-established at execution time and the decision re-run against it
  (ADR 0013, Invariant B1).

A confused deputy arises when a deputy applies its own authority to a target chosen by a less
privileged party. Verdict's answer is that the target is not chosen by that party — only proposed by
it.

### 3. Verdict does not confine the executor

Verdict controls **admission** to the executor. It does not restrict what the executor may reach once
it runs. Removing ambient authority from application code is out of scope, has no general mechanism in
PHP, and belongs to the application's own architecture (ADR 0004).

This bound is not a shortcoming to be fixed later; it is where Verdict's boundary is drawn.

### 4. Naming rule

In Verdict's source, documentation, and ADRs, "capability" always means the Verdict class. Where the
object-capability sense is meant, write "object capability" in full. This rule is recorded in
`docs/glossary.md`.

## Non-goals

- **No `src/` change.** No rename, no API change, no new contract.
- **No claim about confinement.** See Decision §3.
- **No position on whether object capabilities are better in general.** They are a different design
  for a different deployment shape.

## Consequences

- A reviewer who arrives from the object-capability literature gets an explicit answer instead of
  inferring a guarantee Verdict does not make.
- The confused-deputy framing becomes precise and citable, which strengthens the security model
  documentation rather than weakening it — Verdict has an answer, just not that one.
- Proposals to "pass capabilities to the model instead of names" have a written rejection to read
  (below) rather than being re-litigated.

## Alternatives rejected

### Rename `Capability` to avoid the collision

Rejected. The word is standard for named permissions outside one specific literature, the rename
would churn the most public class in the package, and every alternative (`Action`, `Permission`,
`Guard`) collides with a Laravel concept that means something else. A glossary entry costs nothing
and solves the actual problem, which is ambiguity rather than the name.

### Adopt object capabilities: hand the model unforgeable references instead of names

Rejected, and the reason is specific to this deployment rather than general skepticism.

An object capability's defining virtue is that possession *is* authority. In an LLM tool loop, the
handle would have to live in the context window — a medium that is attacker-influenced by
construction and whose whole failure mode is exfiltration. A leaked name produces a denied request;
a leaked object capability produces a successful one. The property that makes object capabilities
strong between mutually suspicious processes inverts when one side of the channel can be induced to
repeat its contents.

There is also no gain to offset it. The handle has to be minted by something that already knows which
object the actor may reach — which is exactly the trusted resolver Verdict already runs. Adding the
handle inserts an indirection without adding a check.

Miller's own answer to the leak problem is the revocable forwarder, which is a delegation mechanism
and therefore ADR 0015's subject, not a reason to reopen this one.

### Confine the executor with a sandbox or a capability-safe PHP subset

Rejected as out of scope. There is no capability-safe subset of PHP to target, process-level
confinement is an infrastructure decision the application owns (ADR 0004), and a partial confinement
that looks like a boundary but is not would be worse than the stated bound in Decision §3.

## Sources

- Dennis, J. B. and Van Horn, E. C. "Programming Semantics for Multiprogrammed Computations."
  *CACM* 9(3), 1966.
- Hardy, N. "The Confused Deputy (or why capabilities might have been invented)."
  *ACM SIGOPS Operating Systems Review* 22(4), 1988.
- Miller, M. S. *Robust Composition: Towards a Unified Approach to Access Control and Concurrency
  Control.* PhD thesis, Johns Hopkins University, 2006.
- Shapiro, J. S., Smith, J. M. and Farber, D. J. "EROS: a fast capability system." SOSP '99.
- `capabilities(7)`, Linux manual pages — the word used for named permissions.
