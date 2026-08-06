# ADR 0011: Rejected — Verdict does not buffer streamed model output for inspection

Status: Accepted (rejection)

## Related issues

- [#19](https://github.com/fissible/verdict/issues/19) (open) documents which security features are verified in streamed and queued execution modes.

## Context

README:1290 already names the tension this ADR resolves: "Streaming output cannot be retracted after
it has been sent; sensitive response checks may need buffering or documented limitations." Verdict
performs context-*release* checks (outbound data leaving the application toward the model, via
`ContextReleaseManager`) and tool-call authorization (via `VerdictManager`), but has no mechanism that
inspects a model's streamed *output* token-by-token before it reaches the caller.

This section records that as a deliberate rejection with reasoning, per the backlog's explicit request
for an "ideas explicitly rejected" category, rather than leaving it as an open tension a future
contributor might try to resolve by adding output buffering.

## Decision

Verdict will not add a mechanism that buffers streamed model output in order to inspect, redact, or
block it before delivery. Streaming sensitive-content risk is a documented limitation, not a feature
gap Verdict intends to close by buffering.

Reasoning:

1. **It contradicts why an application chooses streaming.** Streaming exists to reduce perceived
   latency by delivering tokens as they're generated. Buffering the entire response before release
   reintroduces full-response latency while keeping none of streaming's benefit, for the specific
   responses Verdict would need to inspect most.
2. **Partial buffering doesn't solve the actual risk.** A redaction check needs enough context to
   recognize what it's redacting (a partial credit-card number, a partial address, a sentence whose
   sensitivity only becomes clear once it completes). Buffering only a small window loses exactly the
   cases that most need catching; buffering the whole response is case 1 again.
3. **It's out of scope for what Verdict authorizes.** Verdict's authorization boundary is *tool
   execution* and *context release into the model* — both are discrete, evaluable events with a clear
   before/after. Model-generated free text is not a discrete event Verdict can evaluate once; it's a
   continuous stream owned by Laravel AI and the provider. Verdict remaining "a thin adapter around
   [Laravel AI's] public extension points" (README:1218-1221) means it should not grow a response-
   interception layer Laravel AI itself doesn't provide a public hook for.
4. **The actual mitigations are elsewhere in the design already.** Tool-call arguments and results are
   authorized/classified through `BoundTool`/`GuardedTool` and `ClassifiesToolResult` before they can
   re-enter model context (README:486-539); that is where Verdict's leverage over sensitive data
   actually is. What the model *says* in free text, after legitimate tool results were already
   properly scoped, is a content-moderation problem, which the threat model already excludes:
   "Establish factual correctness or provide general content moderation" (README:1271).

## Consequences

- No streamed-output inspection API is added to Verdict.
- Applications that need to redact or block sensitive content in streamed model output must implement
  that at their own transport layer (e.g. a proxy or a non-streaming fallback for capabilities known
  to risk sensitive disclosure), fully outside Verdict.
- README:1290's existing caveat stands as the accurate statement of this limitation; this ADR
  documents why it will not be closed rather than leaving it open-ended.

## Alternatives rejected

(This ADR is itself the record of a rejected idea; this section covers narrower variants also
considered and rejected alongside the main proposal.)

### Buffer only tool-call-adjacent output, not the full response

Considered as a narrower version of buffering. Still reintroduces full-latency behavior for exactly
the responses most likely to include tool calls, which is disproportionately the traffic Verdict's
users care about, making the latency cost land precisely where it's least acceptable.

### Provide an opt-in buffering mode applications can enable per capability

Rejected because an opt-in flag would still require Verdict to implement and maintain the buffering
and inspection machinery item 2 and 3 above argue against, for a fraction of users, while implying to
everyone else that non-buffered streaming is unchecked by design — which it already is, explicitly,
per README:1290.
