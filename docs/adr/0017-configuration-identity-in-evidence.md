# ADR 0017: Evidence identifies configuration by content, not by name

Status: Accepted

## Related issues

- [#32](https://github.com/fissible/verdict/issues/32) (open) records a configuration fingerprint in
  `DecisionEvidence` (Decision §1).
- [#33](https://github.com/fissible/verdict/issues/33) (open) adds the capability configuration registry
  that makes the fingerprint resolvable (Decision §2–4).
- [#11](https://github.com/fissible/verdict/issues/11) (open) adds tamper-evident evidence. The registry
  described here is an ordinary table and inherits whatever that issue decides; the two are
  complementary, not alternatives.

## Context

`DecisionEvidence` records which policies applied, by **name**:

```php
// src/Evidence/DecisionEvidence.php:12-39 — 26 constructor fields
targetPolicy, targetStrategy, rateLimitPolicy, executionClaimPolicy, ...
```

It records some runtime values (`rateLimitLimit`, `rateLimitRemaining`, `rateLimitResetAt`) but not the
configuration those names refer to, and nothing at all about confirmation configuration or approval TTL.

The consequence is that evidence cannot answer the question an audit actually asks. Two permits a month
apart, under a limit quietly raised from 5/day to 5,000/day, are byte-for-byte indistinguishable in the
policy columns. An auditor can see that a decision was permitted and can see the name of the rule, but
cannot establish what the rule *said* at the time — which is the difference between "this was correct
under the policy in force" and "this was permitted."

Three independent bodies of practice already solved this:

- **Cedar and OPA** pin policy **bundle revisions**. A decision log entry references the exact revision
  that produced it, so a decision is replayable against the policy set that made it.
- **W3C PROV** models it as a `Plan`: `wasAssociatedWith` carries a `hadPlan` naming the specific plan,
  not the plan's role. A plan without a version is not a provenance fact.
- **SCITT** registers the registration policy itself as a signed statement, so the rules governing
  admission are as auditable as the admissions.

Verdict also already owns the right instrument. ADR 0008 establishes a fingerprint-first evidence model:
sensitive and bulky values are recorded as SHA-256 fingerprints rather than raw. Configuration identity
is the same problem with the same answer.

There is a partial precedent in the code, too. ADR 0003 namespaces the approval binding fingerprint with
the execution-target **policy name**, so that a deliberately versioned policy change invalidates pending
receipts (`src/Approvals/ApprovalManager.php:147-161`). That works only when a human remembers to rename
the policy, and fails silently when they do not.

## Decision

### 1. Record a configuration fingerprint alongside every policy name

Evidence carries a `configurationFingerprint`: a SHA-256 over the canonical form of the
**security-material declared configuration** of the capability and its policies, computed at
registration and stable for the life of the process.

In scope of the hash:

- capability name and Laravel ability
- whether confirmation is required, and the confirmation TTL
- execution-target policy name and strategy (`refresh` / `acceptStaleSnapshot`)
- rate-limit policy name, limit, and window
- execution-claim policy name and its declared parameters
- an optional application-supplied `configurationVersion` string

Explicitly out of scope:

- **Closures** — target resolvers, approval bindings, policy binding functions, and executors. They are
  not meaningfully serializable, and hashing their source text is unreliable (see "Alternatives
  rejected").
- **Non-material strings** such as the confirmation `reason`. Cosmetic edits must not invalidate
  anything.
- **Runtime values already recorded separately**, such as `rateLimitRemaining`.

The closure exclusion is a real residual gap: a change to resolver or executor *logic* with identical
declared configuration produces the same fingerprint. This is stated in `docs/limitations.md`. The
`configurationVersion` field is the escape hatch — an application that needs deploy-level precision pins
its release SHA into it, which keeps the granularity decision on the application side, consistent with
ADR 0004.

### 2. Make the fingerprint resolvable to a human-readable configuration

A fingerprint that cannot be expanded supports **detection** but not **reconstruction**. It tells an
auditor that the rules changed between two decisions without telling them what the rules were, which is
half a control.

Verdict therefore maintains a **capability configuration registry**: a store keyed by the configuration
fingerprint, holding the canonical JSON of the materialized configuration, written on first sight and
never updated.

- **Cardinality is small by construction.** One row per distinct configuration ever observed, not per
  decision. A capability whose configuration never changes has exactly one row for the lifetime of the
  system; a change adds one row. This is the content-addressed-store pattern — the log holds the digest,
  the store holds the artifact — as used by git, OCI registries, and Sigstore/Rekor.
- **The fingerprint is the primary key**, so the join from any evidence row to the rules that produced
  it is a single lookup.

### 3. Storage default: a database table, not a cache

The registry is what makes evidence readable. If it can be evicted, evidence becomes unresolvable —
which is the exact failure this ADR exists to prevent. The default is therefore a table on the same
connection Verdict already uses for security state (ADR 0004), with the same migration, retention, and
tenancy story as the rest of Verdict's tables.

- **Redis is rejected as the default.** Eviction under memory pressure, or a flush, silently destroys
  the meaning of historical evidence. It is durable enough for a cache and not durable enough for
  something evidence points at.
- **Object storage is rejected as the default.** It adds an availability dependency on a write path that
  runs during authorization.

A `CapabilityConfigurationStore` contract lets an application substitute either, or anything else. The
default is opinionated, enabled with database evidence recording, and documented in the published
configuration file so that it is a visible choice rather than an implicit one.

### 4. Capability configuration is versioned by content, not by hand

A capability's versioned identity is `name@configurationFingerprint`. Verdict computes it; nobody
maintains it. This is what makes capabilities first-class versioned objects without introducing a
version field that someone must remember to bump — the failure mode ADR 0003's policy-name convention
currently has.

### 5. Configuration identity may participate in the approval binding — opt in for now

Including the configuration fingerprint in the approval binding fingerprint generalizes ADR 0003's
existing policy-name namespacing from "someone remembered to rename the policy" to "any material
configuration change." A pending approval issued under a 5/day limit should not survive that limit
becoming 5,000/day.

It is also disruptive: every deploy that touches a capability's material configuration would invalidate
every pending approval for it, and an application with long-lived approvals and frequent deploys would
feel that immediately.

Decision:

- Verdict computes the fingerprint and records it in evidence **always**.
- It participates in the **approval binding only when the capability opts in**, via
  `->invalidateApprovalsOnConfigurationChange()`.
- The intended default at 1.0 is **on**. Flipping it is a documented breaking change, announced in the
  changelog and upgrade guide, not a silent behavioral shift.

Recording it in evidence is unconditional because it is observational and cannot break anything. Binding
on it changes what gets denied, so it gets a migration path.

## Non-goals

- **Verdict does not become a policy-as-data system.** No bundle format, no policy compiler, no
  distribution mechanism. Cedar and OPA are cited as prior art for the *identity* problem, not as a
  model to reimplement.
- **The registry is not tamper-evident.** It is an ordinary table, exactly like the rest of the evidence
  store (`docs/limitations.md`, "Tamper-evident evidence is opt-in, partial, and bounded by key
  custody"). Issue #11 governs that for evidence generally.
- **Closures are not hashed.** See Decision §1 and "Alternatives rejected."
- **No `src/` change is made by this ADR.** It decides the shape; two issues implement it.

## Consequences

- Evidence can answer "under what rules was this permitted," which is the question that makes an audit
  trail useful rather than merely present.
- A silent configuration change becomes visible as a fingerprint change even when nobody renamed
  anything.
- The registry's small, bounded cardinality means the reconstruction capability costs one extra table
  and one row per configuration change — not per decision.
- Applications that adopt the opt-in binding get automatic invalidation of stale approvals, and
  applications that do not are unaffected until 1.0.
- `docs/limitations.md` gains one honest sentence about the closure gap, which is better than an implied
  guarantee that the fingerprint covers all behavior.

## Alternatives rejected

### Record the full configuration on every evidence row

Rejected. It duplicates a near-constant blob across every decision, and ADR 0008's privacy model
deliberately keeps evidence rows narrow and fingerprint-first. Content addressing produces the same
answer at a small fraction of the storage, with the added property that identical configuration is
provably identical rather than merely equal-looking.

### Record only the fingerprint, with no registry

Rejected. This is detection without reconstruction: an auditor learns that something changed and cannot
learn what. The registry's cost is one row per distinct configuration ever observed, which is negligible
against the value of an expandable hash.

### Require applications to version policy names by hand

This is the status quo and it stays as a *supported* practice (ADR 0003), but it is rejected as the sole
mechanism. It works only when someone remembers, and the failure is silent — precisely the shape of bug
that content addressing eliminates by construction.

### Redis as the default registry store

Rejected. A key that can be evicted must not be the only expansion of a hash stored in an audit record.
It remains available through the store contract for applications that accept the trade.

### Hash closure source text via `ReflectionFunction`

Considered seriously and rejected as a default. `ReflectionFunction::getFileName()` plus a start/end line
range yields source text that changes with whitespace and formatting, changes when unrelated code above
it moves, and is unavailable under some opcache configurations — producing false invalidations, which
for the opt-in approval binding in Decision §5 would mean spurious denials. The `configurationVersion`
escape hatch covers the real need with application-owned semantics and no false positives.

### Include the confirmation `reason` string in the hash

Rejected. It is operator-facing prose. Editing a message for clarity must not invalidate pending
approvals or register a new configuration.

## Sources

- Cedar and Open Policy Agent — policy bundle revision pinning in decision logs.
- W3C. *PROV-DM: The PROV Data Model*, 2013 — `Plan`, `hadPlan`, `wasAssociatedWith`.
- IETF SCITT — registration policy as a registered, signed statement.
- Sigstore/Rekor and the OCI distribution specification — content-addressed artifact storage behind a
  digest-bearing log.
