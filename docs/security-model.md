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

## Authorization

`Capability::usingPolicy()` names a Laravel authorization ability and receives a target resolved by your application. Verdict evaluates that policy before the executor runs.

The model may supply arguments, but it is not the authority that decides whether an actor can act on a record. Target resolvers should load records from trusted storage and enforce tenant or ownership boundaries explicitly where the application needs them.

## Target freshness and TOCTOU

For `BoundTool`, an `ExecutionTargetPolicy` supplies canonical target identity and an execution strategy. `refresh()` re-loads the target immediately before execution; `acceptStaleSnapshot()` makes accepting the original snapshot an explicit choice.

Refreshing reduces the window between authorization and execution, but no in-process package can eliminate every time-of-check/time-of-use race. If the operation depends on mutable state, the application still owns database transactions, row locks, optimistic concurrency, idempotency, and downstream outbox behavior. See [ADR 0003](adr/0003-execution-target-freshness.md).

## Human approval

`requiresConfirmation()` creates a human-approval gate. Its binding resolver produces canonical, application-defined facts such as an account ID, amount, or destination. Approval is for that binding—not for a broad conversational intent—and it is consumed before execution.

The application decides which facts are material. Include every fact whose change would require a new human decision. Streaming approval resumption is intentionally deferred and fails closed; see [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md).

## Preventing duplicate actions

`atMostOnce()` associates an execution-claim policy with a capability. Verdict atomically admits a given configured claim fingerprint at most once. This is useful for effects such as issuing a refund or sending a consequential command.

The guarantee concerns admission by Verdict, not universal exactly-once delivery across every database and external provider. Claim identity and retention are business decisions. Read [ADR 0002](adr/0002-strict-at-most-once-admission.md), [ADR 0004](adr/0004-independent-security-state-transactions.md), and [ADR 0009](adr/0009-execution-claim-retention.md).

## Limiting what AI can do

`rateLimit()` applies a semantic rate-limit policy before execution. A semantic limit measures an application-defined action, such as refunds per actor per day, rather than a provider-specific proxy such as token count.

Select scopes and windows that reflect the risk you are limiting. The current limit implementation and its future meters are described in [ADR 0001](adr/0001-semantic-execution-rate-limits.md) and [ADR 0010](adr/0010-future-semantic-limit-meters.md).

### Shared buckets are composition bounds

Verdict authorizes one action at a time; that per-resource, non-transitive decision is deliberate, but it has no native model of an attack composed from individually permitted actions. A shared bucket supplies the missing volume bound. Give several capabilities the same bucket identity so the limit applies to a category of effect rather than separately to each capability—for example, let several harmless-looking customer-read capabilities share one hourly bucket so an agent cannot walk the customer table by chaining them.

Size that bucket for what an attacker can accomplish during its window, not expected legitimate traffic. It caps blast radius, not intent or selection: an agent can still spend its small allowance on the ten most sensitive records. Cumulative and value meters are the future path to stronger composition controls; see [ADR 0001](adr/0001-semantic-execution-rate-limits.md) and [ADR 0010](adr/0010-future-semantic-limit-meters.md).

## Context release and evidence

Context-release controls govern what application data may be supplied to an AI. Evidence records security-relevant facts with a fingerprint-first privacy model: the package is designed not to persist raw prompt or tool content by default.

That is not a PII detector and it does not classify arbitrary provider payloads. Applications remain responsible for their data classification, provider agreements, logging configuration, and retention obligations. See [ADR 0007](adr/0007-evidence-layering.md) and [ADR 0008](adr/0008-evidence-privacy-model.md).

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
- an untrusted argument directs an action at the wrong resource;
- a consequential action proceeds without required human approval;
- a qualifying action is admitted more than once; and
- model behavior exceeds a configured semantic safety limit.

It does not assume a model is malicious or trustworthy. Instead, it treats model-proposed actions as requests that need ordinary application authorization and safety controls.

For boundaries Verdict does not provide, see [limitations](limitations.md). For execution flow and extension points, see [architecture](architecture.md). For evaluation and executable threat models, see [evaluation harness](evaluation.md).
