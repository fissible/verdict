# ADR 0037: Rejected — the provider's request identity is not Verdict evidence

Status: Accepted (rejection)

## Related issues

- [#18](https://github.com/fissible/verdict/issues/18) (closed) audited Verdict's Laravel AI
  dependency surface. This settles one field of that surface Verdict declines to consume.
- [ADR 0012](0012-rejected-verdict-does-not-own-token-telemetry.md) rejected Verdict owning provider
  token and cost telemetry. This is the same ownership boundary at a different field, and the two
  should be read together.
- [ADR 0007](0007-evidence-layering.md) states what an evidence record is for; [ADR 0008](0008-evidence-privacy-model.md)
  states the opaque-identifier rule that would have *permitted* this field. Permission is not a reason.
- [ADR 0001](0001-semantic-execution-rate-limits.md) and [ADR 0010](0010-future-semantic-limit-meters.md)
  define Verdict's rate limiting as semantic and application-defined, which is why provider
  rate-limit headers are not a near-miss for it.
- [#352](https://github.com/fissible/verdict/issues/352) (open, v1.0.0) asks whether Verdict signals
  proximity to *its own* semantic limit. That is a different question and this decision does not
  touch it.

## Context

`laravel/ai` exposes the provider's HTTP response as `$response->raw`, through
`Responses\Concerns\HasRawResponse` ([laravel/ai#714](https://github.com/laravel/ai/pull/714)). It is
not new: it merged 2026-08-05 and shipped in `v0.11.0`, which is the constraint Verdict already
requires, so the field has been available to Verdict since the dependency was last raised. It carries
provider request ids, rate-limit headers, status codes, and provider-specific body data.

The question raised by its availability: should a Verdict evidence record carry the provider's
request id, so that a recorded decision can be joined to a provider-side incident record?

The case for is real, and worth stating before rejecting it. Evidence already carries
`correlation_id` and `invocation_id`; a provider request id would extend that chain one hop past
Laravel AI to the provider itself. It is an opaque identifier, so ADR 0008 permits it. And Verdict
sits at the moment of the decision, where the correlation is cheapest to make — an application
reconstructing it afterwards has only timestamps.

## Decision

**Verdict does not record the provider's request identity, or any other provider-transport fact, on
evidence.** The join between a Verdict decision and a provider request belongs to the application.

## Why

Four reasons, heaviest first. The first two would matter even if the ownership question were settled
the other way.

**1. The identifier does not mean what the column would claim.** `TextResponse::$raw` is the **last**
step's HTTP response — `TextGenerationLoop` builds the response with
`->withSteps($steps)->withRawResponse($lastResult?->raw)`. A turn that calls tools makes several
round-trips, and the one that emitted the proposal Verdict adjudicated is generally not the last.
A field named for the decision would hold an identifier belonging to a different HTTP call. Per-step
identities do exist — each `Responses\Data\Step` carries its own `raw` — but Verdict does not sit at
that seam, and reaching it would mean taking a dependency on the step loop to record something that
is not part of the decision.

**2. It would be absent for three structural reasons, and absence would read as a fact.** `raw` lives
on `TextResponse`, `Responses\Data\Step`, and `Gateway\StepResponse` — not on `AgentResponse` or
`StreamableAgentResponse`, which are what Verdict's middlewares hold, and not on the
`PromptingAgent`, `StreamingAgent`, or `ToolInvoked` events Verdict listens to.
`HasRawResponse::__serialize()` explicitly unsets it, so it does not survive a queue. And it is
nullable by contract — "if available for the provider". A column null for any of those three reasons
invites an auditor to read "no provider call", which is the exact confusion the receipt's
never-captured-versus-none distinction exists to prevent. Making it honest requires a tri-state —
captured, unavailable from this provider, not capturable at this seam — that Verdict would then owe
across every provider adapter, forever, for a field that is not evidence.

**3. It is a provider-transport fact, and those are already assigned.** The
[ownership table](../architecture.md#relationship-to-laravel-ai) puts generation limits and events in
Laravel AI's column, and ADR 0012 has already rejected Verdict becoming the system of record for
provider-side measurements. Rate-limit headers are the clearest case: Verdict's rate limiting is
semantic — a meter over *actions*, defined by the application — not provider quota over tokens.
Reading `x-ratelimit-remaining` into Verdict would blur precisely the line ADR 0001 and ADR 0010
drew, and would do it in the layer whose whole value is that its boundaries are legible.

**4. Evidence answers a different question.** A Verdict record states whether an action was
admissible and why. A provider request id describes the HTTP transaction that produced the text that
led to a proposal. That is useful context, but it is not part of the decision's justification, and an
evidence schema that accretes adjacent context is one whose scope stops being readable — which is the
property the whole evidence layer is built to have.

## What the application does instead

The join needs no new Verdict field, because both ends already exist. Verdict's evidence carries the
Laravel AI `invocation_id`, and the application holds the response object with `raw` on it in its own
middleware or calling code. Correlating them is one table the application owns, on identifiers it can
already see from both sides. This is documented under
[Referencing a Verdict claim from another system](../evidence-record-identity.md#joining-a-verdict-record-to-a-provider-request).

Placing it there is not a consolation. It is the correct owner: the application knows which provider
account, which environment, and which retention policy the provider identifier belongs to, and
Verdict knows none of those.

## What would reopen this

Reasons 1 and 2 are properties of the current upstream shape, not of the idea. If Laravel AI carried
a per-proposal provider request identity on the agent-run surface Verdict already observes — an event
field, or the agent response — and it survived serialization, both would weaken.

Reasons 3 and 4 would still stand. So an upstream change is necessary but not sufficient to reopen
this, and reopening it would need an argument that the identifier is part of the *decision*, not
merely available near it.

## Consequences

- No schema change, no migration, no new capture seam. This decision costs nothing to implement,
  which is the point of recording it: the next person to notice `$response->raw` finds the answer
  rather than the question.
- Verdict's evidence stays answerable from Verdict's own records. Nothing in a decision row depends
  on a provider's response object having been reachable, serializable, or non-null.
- An operator correlating a Verdict decision to a provider incident does so through the application's
  join, and the documentation says so rather than leaving them to discover the gap during an incident.
