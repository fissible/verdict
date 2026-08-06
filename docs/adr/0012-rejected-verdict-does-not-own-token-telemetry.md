# ADR 0012: Rejected — Verdict does not collect or own provider token/cost telemetry

Status: Accepted (rejection, narrowed from the original proposal)

## Related issues

- [#18](https://github.com/fissible/verdict/issues/18) (open) audits Verdict's Laravel AI dependency surface and undocumented assumptions, including provider-owned behavior.

## Context

The backlog's "ideas explicitly rejected" suggestion named "making Verdict responsible for provider
token telemetry" as something that would push the package away from its core philosophy. Taken
literally, that would contradict the roadmap item "Token or cost budgets per tenant," listed under
"Later semantic limits may include" and carried into
[ADR 0010](0010-future-semantic-limit-meters.md)'s context section, and contradicts
[ADR 0010](0010-future-semantic-limit-meters.md)'s cumulative/value meter category, which the backlog
itself asked to have designed. This ADR resolves that conflict by rejecting the part of the idea that
actually conflicts with Verdict's philosophy, rather than rejecting token/cost budgeting outright.

Verdict's own ownership table
([architecture: relationship to Laravel AI](../architecture.md#relationship-to-laravel-ai)) already
assigns "Generation limits and events" to Laravel AI's column, not Verdict's — the framework this
backlog item was gesturing at is already encoded in the docs; it just hadn't been stated as a
rejection with reasoning.

## Decision

Verdict does not collect, measure, or become the system of record for provider token usage or
monetary cost. That remains Laravel AI's and the provider adapter's responsibility, consistent with
the existing ownership table.

What Verdict *may* do, and what ADR 0010 correctly still lists as a live roadmap item,
is **consume a usage/cost value that Laravel AI already reports** as input to a policy decision — the
same way ADR 0001's rate-limit policy consumes a fixed unit per attempt. The distinction:

- **Rejected:** Verdict instruments provider calls, tracks token counts or dollar cost itself, or
  becomes an alternative source of truth for usage that could disagree with the provider's own
  billing.
- **Not rejected, still on the roadmap:** a semantic-limit policy (per ADR 0010's cumulative/value
  meter category) that reads a usage or cost figure already present on a Laravel AI response/event and
  uses it to permit, deny, or require confirmation for further action — exactly as it already reads a
  resolved target or a canonical binding, without owning where that number came from.

This mirrors the same boundary Verdict draws everywhere else in the ownership table: Laravel AI owns
provider mechanics, Verdict owns the authorization decision built on top of application-trusted
inputs.

## Consequences

- No token/cost measurement code is added to Verdict independent of what a future ADR 0010
  implementation slice consumes from Laravel AI's own reporting.
- The "Token or cost budgets per tenant" roadmap bullet stands as accurate and is not removed or
  reworded by this ADR — it describes the consuming-policy version of this idea, not the rejected
  telemetry-ownership version.
- A future contributor proposing "Verdict tracks token usage" should read this ADR as a rejection of
  that specific framing, and be redirected to ADR 0010's cumulative-meter category instead.

## Alternatives rejected

### Reject token/cost budgets from the roadmap entirely

Rejected as too broad: it would contradict ADR 0010 and remove a legitimate, already-scoped
roadmap item to resolve a narrower objection. The actual concern (Verdict becoming a telemetry/billing
system) is fully addressed by restricting Verdict to consuming already-reported values.

### Have Verdict independently re-measure token usage as a safety net against provider under-reporting

Rejected because Verdict has no way to independently verify token counts without duplicating
provider-specific tokenization logic per model/provider, which is exactly the provider-mechanics
ownership Laravel AI already holds
([architecture: relationship to Laravel AI](../architecture.md#relationship-to-laravel-ai)). A
disagreeing second source of truth would be
more confusing than trusting the one Laravel AI already reports.
