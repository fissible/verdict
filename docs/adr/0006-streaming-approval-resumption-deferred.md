# ADR 0006: Streaming approval resumption is deferred, not rejected

Status: Accepted

## Context

Verdict's confirmation flow (README:213-296) resumes a Laravel AI agent after approval by calling
`$agent->prompt(Decisions::from([...]))` inside a synchronous request/response cycle
(README:273-275). README:293-296 already states the current limitation plainly: "Streaming approval
resumption is not yet supported because agent middleware returns before a stream is consumed;
protected execution will fail closed without the scoped approval context." `GuardedTool` cannot
support verified confirmation at all, for the unrelated reason covered in
[ADR 0005](0005-guardedtool-is-a-bounded-migration-bridge.md).

The architecture-review backlog asked whether streamed approval resumption is feasible,
architecturally compatible, or intentionally out of scope, and what Laravel AI capability it would
require. The roadmap table (README:1441-1458) does not list a streaming milestone, which reads as
silent scope-narrowing rather than a stated decision. This ADR exists to make that decision explicit
and distinguish "not designed yet" from "rejected."

## Decision

Streaming approval resumption is **deferred**, not rejected. It is not implemented today because:

1. Laravel AI's agent middleware — where `VerdictApprovalMiddleware` and the confirmation challenge
   live — returns control before a streamed response is fully consumed. A `PendingApproval` raised
   mid-stream has no synchronous point at which Verdict can pause the stream, surface the challenge,
   and later resume emission from the same position without Laravel AI providing that resumption
   primitive itself.
2. Verdict's approval scope is deliberately per-request (README:254-280): an endpoint resolves the
   challenge only after it has already authorized access to the conversation and pending call. A
   streamed approval would need an equivalent scoped resumption point — a token or callback Laravel
   AI issues mid-stream that an authorized endpoint can later use to resume emission — which does not
   currently exist in Laravel AI's public surface.

Verdict does not need to design this speculatively. When Laravel AI's streaming API exposes a
resumable mid-stream approval point (a callback, a resumption token, or an equivalent), Verdict
should evaluate binding `VerdictApprovalMiddleware` to it. Until then, capabilities that require
confirmation and might run under a streamed agent must fail closed (as they already do), not silently
skip the approval boundary.

## Consequences

- No new API surface is added by this ADR. `BoundTool` and `GuardedTool` behavior is unchanged.
- README:293-296 already states the limitation; this ADR adds the reasoning behind it so a future
  contributor can evaluate whether a new Laravel AI capability actually closes the gap, rather than
  re-litigating whether the gap is intentional.
- A future capability in Laravel AI that provides a scoped mid-stream resumption point should trigger
  revisiting this ADR, not a fresh design discussion from zero.

## Alternatives rejected

### Buffer the entire streamed response and emit it only after approval

This defeats the purpose of streaming and does not solve the underlying problem: the tool call
requiring approval happens *during* generation, not after it. Buffering the output has no bearing on
where in the Laravel AI middleware chain the approval challenge would need to pause. (This is a
distinct question from buffering for redaction — see
[ADR 0011](0011-rejected-verdict-does-not-buffer-streamed-output.md).)

### Build a Verdict-owned streaming resumption mechanism independent of Laravel AI

Verdict deliberately remains a thin adapter around Laravel AI's public extension points
(README:1218-1235). Implementing stream pausing/resumption itself would duplicate transport-layer
concerns Laravel AI owns and would need to be reconciled with Laravel AI's own streaming API the
moment one exists, likely incompatibly.

### Silently treat streamed tool calls that require confirmation as denied, without documenting why

This is closer to today's actual failure mode, but leaving it undocumented would let a contributor
mistake the gap for an oversight and attempt to "fix" it by weakening the approval boundary instead
of by closing the actual missing capability.
