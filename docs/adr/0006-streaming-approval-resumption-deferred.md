# ADR 0006: Streaming approval resumption is deferred, not rejected

Status: Accepted (corrected — see "Correction" below)

## Related issues

- [#19](https://github.com/fissible/verdict/issues/19) (implemented) documents streaming and queued compatibility explicitly.
- [#22](https://github.com/fissible/verdict/issues/22) (open) tracks the Verdict-side approval-context lifetime fix.

## Correction

This ADR originally claimed streaming approval resumption was blocked on a Laravel AI capability gap:
"a token or callback Laravel AI issues mid-stream that an authorized endpoint can later use to resume
emission — which does not currently exist in Laravel AI's public surface." That claim is inaccurate.
Laravel AI v0.10.2 (the vendored version) already provides everything this would require:

- `Laravel\Ai\Contracts\Approvable` / `Laravel\Ai\Concerns\InteractsWithApprovals`, which
  `AbstractVerdictTool` already implements, wiring `shouldRequestApproval()` directly into
  `VerdictManager::requestConfirmation()`.
- `Laravel\Ai\Streaming\Events\ToolApprovalRequest`, a stream event carrying `pendingApprovals` and
  raw `providerContentBlocks` explicitly for replay when a stream pauses for approval.
- Resumption via `AgentPrompt->approvalDecisions` / `Decisions` — a fresh-request replay, the exact
  mechanism Verdict's synchronous confirmation flow already uses today
  ([architecture: resolving an approval](../architecture.md#resolving-an-approval)).

The real blocker is Verdict-side, tracked in
[issue #22](https://github.com/fissible/verdict/issues/22): see "Decision" below.

**Update:** Issue #22 has landed. `VerdictApprovalMiddleware` now keeps `ApprovalExecutionContext`'s frame alive through a streamed response's full iteration, not just until the middleware call returns synchronously. Streaming approval resumption is supported as of this change.

## Context

Verdict's confirmation flow resumes a Laravel AI agent after approval by calling
`$agent->prompt(Decisions::from([...]))` inside a synchronous request/response cycle
([architecture: resolving an approval](../architecture.md#resolving-an-approval)), which states the
current limitation plainly: "Streaming approval resumption is not yet supported, because agent
middleware returns before a stream is consumed; protected execution fails closed without the scoped
approval context." `GuardedTool` cannot
support verified confirmation at all, for the unrelated reason covered in
[ADR 0005](0005-guardedtool-is-a-bounded-migration-bridge.md).

The architecture-review backlog asked whether streamed approval resumption is feasible,
architecturally compatible, or intentionally out of scope, and what Laravel AI capability it would
require. No release milestone schedules streaming approval resumption, which reads as
silent scope-narrowing rather than a stated decision. This ADR exists to make that decision explicit
and distinguish "not designed yet" from "rejected."

## Decision

Streaming approval resumption is **deferred**, not rejected. It is not implemented today because of a
Verdict-side context-lifetime bug, not a missing Laravel AI capability:

`VerdictApprovalMiddleware::handle()` wraps `$next($prompt)` in `ApprovalExecutionContext::within()`,
which pushes an "approved tool call IDs" frame, runs the callback, and pops the frame in a `finally`
block as soon as the callback *returns*. For a synchronous prompt, `$next($prompt)` fully resolves the
turn — including tool execution — before returning, so the frame is present for the whole time
`ApprovalManager::executionStateFailure()` needs it. For a streamed prompt, `$next($prompt)` returns a
`Laravel\Ai\Responses\StreamableAgentResponse` immediately: it wraps a *lazy* generator, so the actual
tool execution (and the approval check) only happens later, during iteration — after `within()`'s
`finally` block has already popped the frame. `ApprovalExecutionContext::allows($toolCallId)` then
finds an empty frame stack and `ApprovalManager::executionStateFailure()` fails closed with
`ApprovalOutcome::InvalidState`, even for an already-approved decision.

Verdict's approval scope is deliberately per-request
([architecture: resolving an approval](../architecture.md#resolving-an-approval)): an endpoint resolves the
challenge only after it has already authorized access to the conversation and pending call. Extending
`ApprovalExecutionContext`'s frame lifetime across a streamed response's iteration — rather than
popping it when the middleware callback merely *returns* — closes this gap using primitives Laravel AI
already exposes. This is scoped implementation work, tracked in
[issue #22](https://github.com/fissible/verdict/issues/22), not a design question awaiting an upstream
capability. Until issue #22 lands, capabilities that require confirmation and might run under a
streamed agent must continue to fail closed (as they already do), not silently skip the approval
boundary.

## Consequences

- No new API surface is added by this ADR. `BoundTool` and `GuardedTool` behavior is unchanged.
- The architecture guide already states the limitation; this ADR adds the reasoning behind it, corrected to
  identify the actual cause and point at issue #22 rather than an upstream capability gap.
- Issue #22 (extending `ApprovalExecutionContext`'s scope across `StreamableAgentResponse`
  consumption) is the concrete next step. Closing it resolves this ADR's limitation without requiring
  any new Laravel AI capability.

## Alternatives rejected

### Buffer the entire streamed response and emit it only after approval

This defeats the purpose of streaming and does not solve the underlying problem: the tool call
requiring approval happens *during* generation, not after it. Buffering the output has no bearing on
where in the Laravel AI middleware chain the approval challenge would need to pause. (This is a
distinct question from buffering for redaction — see
[ADR 0011](0011-rejected-verdict-does-not-buffer-streamed-output.md).)

### Build a Verdict-owned streaming resumption mechanism independent of Laravel AI

Verdict deliberately remains a thin adapter around Laravel AI's public extension points
([architecture: relationship to Laravel AI](../architecture.md#relationship-to-laravel-ai)).
Implementing stream pausing/resumption itself would duplicate transport-layer
concerns Laravel AI owns and would need to be reconciled with Laravel AI's own streaming API the
moment one exists, likely incompatibly.

### Silently treat streamed tool calls that require confirmation as denied, without documenting why

This is closer to today's actual failure mode, but leaving it undocumented would let a contributor
mistake the gap for an oversight and attempt to "fix" it by weakening the approval boundary instead
of by closing issue #22, the actual fix.
