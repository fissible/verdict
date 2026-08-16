# Verdict security model

Verdict is an application-controlled authorization boundary for AI-triggered actions. It protects the capability path you register and execute through Verdict; it does not make an entire AI application safe by itself.

Terms used below are defined in the [glossary](glossary.md).

The central boundary is simple: a model proposes a capability and arguments, while the application resolves the target, evaluates policy, and decides whether the executor may run.

## Independent policies

Verdict does not combine every safeguard into a hidden score or a single, generic allow/deny rule. Each capability carries the policies that make sense for that operation.

| Policy area | What it answers | Typical application responsibility |
| --- | --- | --- |
| Authorization | May this actor perform this capability on this target? | Define Laravel policies and pass a trusted actor in `ActionContext`. |
| Target binding | Which resource should the executor use? | Resolve from trusted data and define an `ExecutionTargetPolicy`. |
| Human approval | Must a person approve this operation? | Define canonical binding facts, reason, and expiry. |
| Replay protection | Has this action already been admitted? | Choose the claim identity and retention appropriate to the business operation. |
| Semantic limits | Has the actor exceeded a safety limit? | Define the business event, scope, window, and threshold. |
| Context and evidence | What may be released or retained? | Classify sources and choose a data-retention posture. |

Authorization and target binding are the foundation of a protected capability. Confirmation, execution claims, rate limits, and context/evidence controls are independent additions. A capability should use the smallest set that adequately protects its real side effect.

<!-- @verdict-claim security.authorization tested -->
## Authorization

`Capability::usingPolicy()` names a Laravel authorization ability and receives a target resolved by your application. Verdict evaluates that policy before the executor runs.

The model may supply arguments, but it is not the authority that decides whether an actor can act on a record. Target resolvers should load records from trusted storage and enforce tenant or ownership boundaries explicitly where the application needs them.

### Authority is not intent

Authorization answers one question: may this actor perform this operation on this
record? It cannot answer a second question: did this actor want it?

Under prompt injection the actor is the legitimate authenticated user. If injected
content selects a record the actor is authorized for, every authorization check in
Verdict permits the action, correctly. The gap is not in the policy evaluation. It is
that the target was chosen by something other than the user.

This is why target provenance matters more than target validation. Verdict distinguishes
two resolution paths:

- **Context-resolved targets.** The resolver reads from `ActionContext`, which the
  application builds per invocation from state the model cannot influence. An injected
  instruction cannot change which record is acted on, only whether an action is
  proposed at all. This is the recommended path for consequential operations.
- **Proposal-resolved targets.** The resolver reads from
  `ActionEnvelope::$proposal->arguments`. Scoping the lookup to the actor bounds which
  records are reachable. It does not establish that the actor chose this one.

Verdict's authorization layer bounds authority on both paths. Only the first bounds
selection. For proposal-resolved targets on consequential operations, the intent
control is human approval, not the policy.

That distinction is executable, not just prose:
`StorefrontScenarioRunner::contextResolvedTargetDifferential()` runs one injected argument
— naming a *different* order the actor also owns — through two capability registrations,
and records which record each acted on. The proposal-resolved registration is redirected to
the injected order; the context-resolved one holds to the intended order and ignores the
injection. This measures a capability property: a context-resolved target is not redirectable
by an injected argument. It does **not** make intent determinable — Verdict still cannot tell a
wanted action from an unwanted one, only remove the model's ability to choose the record
(`limitation.intent` stays untestable). Making the resolution path visible in evidence is a
separate mechanism, tracked in [#192](https://github.com/fissible/verdict/issues/192).

<!-- @verdict-claim capability.context-resolved-target tested -->

### Actor and subject evidence

`ActionContext::$actor` is the principal acting and is the value Verdict passes to Laravel's Gate. Its optional `$subject` is the principal on whose behalf that actor acts; when it is `null`, the actor acts for itself. A subject is not a delegator: delegation attenuates an existing authority, while an actor acting for a subject may instead reflect a separately authorized escalation. See [ADR 0015](adr/0015-authority-propagation.md).

To record either principal in decision evidence, the application opts in by implementing `ProvidesVerdictIdentity` on its principal object:

```php
use Fissible\Verdict\Contracts\ProvidesVerdictIdentity;

final class SupportAgent implements ProvidesVerdictIdentity
{
    public function verdictIdentity(): string
    {
        return "support-agent:{$this->id}";
    }
}
```

Verdict stores only the SHA-256 fingerprint of this application-supplied string in `actor_fingerprint` and `subject_fingerprint`; it never guesses an identity from an object hash, serialization, or raw value. Values that do not implement the contract produce nullable identity evidence, and an empty supplied identity is rejected.

<!-- @verdict-claim security.target-freshness tested -->
## Target freshness and TOCTOU

For `BoundTool`, an `ExecutionTargetPolicy` supplies canonical target identity and an execution strategy. `refresh()` re-loads the target immediately before execution; `acceptStaleSnapshot()` makes accepting the original snapshot an explicit choice.

Refreshing reduces the window between authorization and execution, but no in-process package can eliminate every time-of-check/time-of-use race. If the operation depends on mutable state, the application still owns database transactions, row locks, optimistic concurrency, idempotency, and downstream outbox behavior. See [ADR 0003](adr/0003-execution-target-freshness.md).

<!-- @verdict-claim security.approval-binding tested -->
## Human approval

`requiresConfirmation()` creates a human-approval gate. Its binding resolver produces canonical, application-defined facts such as an account ID, amount, or destination. Approval is for that binding—not for a broad conversational intent—and it is consumed before execution.

The application decides which facts are material. Include every fact whose change would require a new human decision. Streaming approval resumption is intentionally deferred and fails closed; see [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md).

### Avoiding confirmation fatigue

Confirmation is a security control only while the approver reads it. Prompt rate is therefore a security parameter: a prompt at five times a week can be meaningful, while the same prompt at fifty times a day becomes a rubber stamp. Confirm irreversible or expensive actions, not routine ones; low-consequence prompts train an approver to dismiss the consequential prompt that follows.

Do not batch requests. “Approve these 20 refunds” approves a category, not one concrete request, and defeats the argument binding that exists to bind a human decision to one request. Show the approver every material binding fact—such as amount, destination, and target—because an approver who cannot see an amount cannot meaningfully approve it.

Prefer `rateLimit()` and `atMostOnce()` where they fit: both bound risk without consuming human attention. Instrument the flow as well. `approvalOutcome` is already recorded in decision evidence, so the approval-to-denial ratio is a useful check; an approval flow that has never produced a denial may not be read.

### Sizing approval TTLs

Separate the human **approve → execute** interval from the machine **validate → execute** interval. The first can be minutes or hours; expiry races only with the second, which begins when Verdict revalidates the approved receipt against the refreshed execution target and ends when it consumes that receipt. Set `ttlSeconds` well above the worst-case validate → execute latency—not the median or p99—including queue depth, a slow executor, claim retries, and paused-stream resumption. A practical starting point is the measured worst case with generous operational headroom, then review it whenever those paths change.

Do not raise a TTL merely because expiry errors appear: first identify the latency source. Treating expiry as flakiness turns a fail-closed control into a longer-lived one. Choose TTL and target strategy together: `refresh()` re-establishes the target immediately before execution, whereas `acceptStaleSnapshot()` leaves a longer-lived approval exposed to more stale state. Material binding facts already invalidate a receipt when they change between proposal and execution, so expiry is a backstop rather than the primary freshness control. See [ADR 0003](adr/0003-execution-target-freshness.md).

<!-- @verdict-claim security.execution-claims tested -->
## Preventing duplicate actions

`atMostOnce()` associates an execution-claim policy with a capability. Verdict atomically admits a given configured claim fingerprint at most once. This is useful for effects such as issuing a refund or sending a consequential command.

The guarantee concerns admission by Verdict, not universal exactly-once delivery across every database and external provider. Claim identity and retention are business decisions. Read [ADR 0002](adr/0002-strict-at-most-once-admission.md), [ADR 0004](adr/0004-independent-security-state-transactions.md), and [ADR 0009](adr/0009-execution-claim-retention.md).

For a target-bound executor, `AuthorizedAction::executionIdentity()` is the raw opaque claim ID suitable for a downstream idempotency key. The execution-claim evidence records `hash('sha256', $identity)`, not the raw value. During reconciliation, hash the provider-side key and compare it with `execution_claim_fingerprint` in the evidence record; `php artisan verdict:execution-claims` shows the raw IDs of unresolved claims.

<!-- @verdict-claim security.rate-limits tested -->
## Limiting what AI can do

`rateLimit()` applies a semantic rate-limit policy before execution. A semantic limit measures an application-defined action, such as refunds per actor per day, rather than a provider-specific proxy such as token count.

Select scopes and windows that reflect the risk you are limiting. The current limit implementation and its future meters are described in [ADR 0001](adr/0001-semantic-execution-rate-limits.md) and [ADR 0010](adr/0010-future-semantic-limit-meters.md).

### Shared buckets are composition bounds

Verdict authorizes one action at a time; that per-resource, non-transitive decision is deliberate, but it has no native model of an attack composed from individually permitted actions. A shared bucket supplies the missing volume bound. Give several capabilities the same bucket identity so the limit applies to a category of effect rather than separately to each capability—for example, let several harmless-looking customer-read capabilities share one hourly bucket so an agent cannot walk the customer table by chaining them.

Size that bucket for what an attacker can accomplish during its window, not expected legitimate traffic. It caps blast radius, not intent or selection: an agent can still spend its small allowance on the ten most sensitive records. Cumulative and value meters are the future path to stronger composition controls; see [ADR 0001](adr/0001-semantic-execution-rate-limits.md) and [ADR 0010](adr/0010-future-semantic-limit-meters.md).

<!-- @verdict-claim security.context-release tested -->
## Context release and evidence

Context-release controls govern what application data may be supplied to an AI. Evidence records security-relevant facts with a fingerprint-first privacy model: the package is designed not to persist raw prompt or tool content by default.

That is not a PII detector and it does not classify arbitrary provider payloads. Applications remain responsible for their data classification, provider agreements, logging configuration, and retention obligations. See [ADR 0007](adr/0007-evidence-layering.md) and [ADR 0008](adr/0008-evidence-privacy-model.md).

### Redaction paths are validated against the allowlist, not the payload

A redaction path that no allowed path can ever match is rejected when the release runs, because a redaction that silently scrubs nothing leaves the field it was meant to protect released in full. Naming `user.social_security` when the allowlist permits `user.socialSecurity` raises `UnreachableTransformerFieldPath` and names the offending path.

The comparison is configuration against configuration. A path that matches nothing in *this particular payload* is legitimate and is not reported: a wildcard over an empty collection (`items.*.ssn` when `items` is empty), or an optional field this record happens to lack. Only a path unreachable under every allowed path is an error.

**This check does not reach inside a subtree allowlist.** `only(['user'])` allows everything beneath `user`, so both `user.socialSecurity` and the misspelled `user.social_security` are reachable in principle and the typo is undetectable. If you want the check to protect a field, allowlist that field explicitly rather than its parent. `withoutFieldPathValidation()` disables the check for a release whose projected shape varies in ways the operator knows about.

### Reviewing one Laravel AI invocation

When database evidence is enabled, every provenance entry, Verdict decision, and context-release record made during a Laravel AI invocation has the same indexed `invocation_id`. Retrieve the complete observed record set with one indexed lookup:

```php
$records = DB::table('verdict_evidence')
    ->where('invocation_id', $invocationId)
    ->orderBy('recorded_at')
    ->orderBy('id')
    ->get();
```

Decision rows retain their envelope identifier in `correlation_id`; it is deliberately separate from `invocation_id`.

An `invocation_id` is a containment fact: Verdict observed that those records occurred during the same Laravel AI invocation. It does not establish that a particular provenance entry influenced, caused, or derived a decision. Derivation edges are deliberately separate work in #30; consumers must not infer causality from co-occurrence.

### Tracing declared derivations backward

`verdict_provenance_derivations` records only a declared or directly observed content transformation. Given an invocation and a child content fingerprint, a recursive query returns its transitive contributing provenance entries:

```sql
WITH RECURSIVE ancestors(content_fingerprint) AS (
    SELECT parent_content_fingerprint
    FROM verdict_provenance_derivations
    WHERE correlation_id = :invocation_id
      AND child_content_fingerprint = :child_content_fingerprint

    UNION

    SELECT edge.parent_content_fingerprint
    FROM verdict_provenance_derivations AS edge
    JOIN ancestors ON edge.child_content_fingerprint = ancestors.content_fingerprint
    WHERE edge.correlation_id = :invocation_id
)
SELECT evidence.*
FROM verdict_evidence AS evidence
JOIN ancestors ON evidence.content_fingerprint = ancestors.content_fingerprint
WHERE evidence.record_type = 'provenance'
  AND evidence.invocation_id = :invocation_id
ORDER BY evidence.recorded_at, evidence.id;
```

The derivation table has a `(correlation_id, child_content_fingerprint)` index for each backward traversal step.

## Threat model

Verdict is designed to make these failures less likely on its protected path:

- a model proposes an action the actor is not authorized to take;
- an untrusted argument directs an action at a resource outside the actor's authority;
- a consequential action proceeds without required human approval;
- a qualifying action is admitted more than once; and
- model behavior exceeds a configured semantic safety limit.

Verdict does not attempt to determine whether an authorized action reflects the
actor's intent. Where the target is proposal-resolved, an injected instruction that
stays inside the actor's authority will pass authorization by design. See
[authority is not intent](#authority-is-not-intent) and
[limitations](limitations.md#authorization-bounds-authority-not-intent).

It does not assume a model is malicious or trustworthy. Instead, it treats model-proposed actions as requests that need ordinary application authorization and safety controls.

For boundaries Verdict does not provide, see [limitations](limitations.md). For execution flow and extension points, see [architecture](architecture.md). For evaluation and executable threat models, see [evaluation harness](evaluation.md).
