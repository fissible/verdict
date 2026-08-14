# ADR 0020: Verdict owns the live trial protocol; the application owns trial state

Status: Accepted

## Related issues

- [#137](https://github.com/fissible/verdict/issues/137) raised the unsound multi-trial rates this ADR settles.
- [#51](https://github.com/fissible/verdict/issues/51) shipped the live runner and recorded a deliberately
  single-trial run because of this defect.
- [#138](https://github.com/fissible/verdict/issues/138) depends on it — a sample-size floor expressed in
  trials measures nothing until trials are independent.

## Context

`LiveEvaluationRunner` accepts a constructed `SecuritySuite` and loops `$suite->run($clock)` once per trial.
Nothing resets anything between iterations. When an application's fixtures or security state persist across
a run — the normal case — trial N observes what trial N-1 did.

The failure is not loud. It surfaces as a *pass rate*: an approval receipt or execution claim created in
trial 1 changes trial 2's disposition, and the aggregate reports a model failure the model had no part in.
A published "3 of 5 trials passed" asserts five independent observations it does not have.

Two obvious fixes were measured before this decision, and both fail.

**Rebuilding the suite per trial does not isolate a trial.** A factory called once per trial produced
`make_calls = 2` and `executions = 1`: the second trial was still refused by the first trial's execution
claim. Constructing a new `SecuritySuite` produces new cases, a new capture, and a new observer — none of
which is where the state lives.

**Flushing the container's scoped instances does not isolate a trial either.** `forgetScopedInstances()`
leaves the same claim-store instance in place. The operational stores are singletons bound by Verdict
itself:

| Binding | Lifetime | Site |
|---|---|---|
| `ApprovalReceiptStore` | `singleton` | `src/VerdictServiceProvider.php:110` |
| `RateLimitStore` | `singleton` | `src/VerdictServiceProvider.php:311` |
| `ExecutionClaimStore` | `singleton` | `src/VerdictServiceProvider.php:342` |
| `CapabilityRegistry` | `singleton` | `src/VerdictServiceProvider.php:99` |
| `VerdictManager` | `scoped` | `src/VerdictServiceProvider.php:398` |

That binding is correct and is not in question. One store per process is what a rate limit, an approval
receipt, and an at-most-once claim all mean in production. A store that reset itself between logical
operations would not be a security control.

So the state that must be reset is reachable only through decisions the application made: which stores it
bound, which fixtures it seeded, which database it pointed at, which fake provider it installed. Verdict
cannot enumerate it, and an attempt to reset it generically would either miss most of it or reach into
state Verdict does not own.

## Decision

**Verdict owns the trial protocol and refuses invalid measurement. The application owns resetting the state
a trial's measurement depends on.**

Concretely:

1. **A multi-trial run requires a trial-capable factory.** Its single operation means *"reset
   application-owned evaluation state, then produce this trial's suite."* Reset and construction are one
   operation, not `make()` alongside an optional `reset()`, so the contract cannot be half-implemented.

2. **That operation runs before every trial, including the first.** A process or database already used
   before the run contaminates trial 1 exactly as it would trial 2. Resetting only *between* trials would
   make the first observation the unreliable one, which is the observation most likely to be quoted alone.

3. **A run configured for more than one trial without that capability throws before any model invocation.**
   Not a warning. The defect this ADR fixes reached a published number precisely because the unsound path
   stayed available and silent; a warning preserves that. Failing before the first invocation also means the
   operator loses no model time to a run whose output could not have been used.

4. **The single-trial path is unchanged and requires no reset.** One trial makes no independence claim, so
   there is nothing to invalidate. An application that only ever runs one trial is not asked to implement
   anything new.

5. **Aggregation is keyed by case identity, never by array position.** The runner previously built counters
   from `$suite->cases` and indexed them positionally, which is safe only while one suite instance is reused.
   Once a suite is rebuilt per trial, positional indexing would attribute one case's result to another
   silently.

6. **Every rebuilt suite must match the first on identity, and a mismatch is rejected rather than
   reconciled.** The suite name and version, the set of case identities, and each case's immutable metadata —
   `id`, `version`, `purpose`, and the setup and input fingerprints — must be equal. Because aggregation is
   keyed, reordering is harmless and needs no special handling; a case that *changed* between trials is a
   configuration error and says so.

## Consequences

An application that wants multi-trial rates must state how its own state is reset. That is more work than
an optional hook, and it is deliberate: the requirement is exactly the thing that was previously assumed and
silently untrue.

Verdict gives up the ability to produce a multi-trial rate for an arbitrary suite. That capability was never
real — it produced numbers, not measurements.

The trial-capable operation receives the trial index. An implementation may use it, but must not depend on
it for correctness: the protocol guarantees the operation runs before each trial, not that trials are
distinguishable.

This ADR does not say how an application should reset. Truncating tables, rebinding fresh in-memory stores,
re-seeding fixtures, and rolling back a transaction are all valid, and the right one depends on what the
application put in the way. It says only that the application must be the one to do it, and that Verdict
will not publish a rate that assumes it happened.

## Alternatives considered

**Document a trial-idempotence requirement and change no code.** Rejected. It pushes a subtle requirement
onto every adopter and fails silently when missed — which is the exact failure mode being fixed, and how it
was found.

**Reset seam as an optional interface, warning when absent.** Rejected for point 3's reason: an invalid
percentage that is merely warned about remains available to publish, and warnings are read after the number
is quoted, not before.

**Have Verdict reset its own stores between trials.** Rejected. It fixes only the part of the state Verdict
owns, leaving application fixtures — the storefront `Catalog`, `ActionLog`, and `SupportNoteChannel` in the
workbench — untouched, producing a partial isolation more dangerous than none because it looks complete. It
would also require the operational stores to expose a reset that has no legitimate production caller.

**Default `trials` to 1 and leave multi-trial unsound.** Rejected. It removes the symptom from the default
path while leaving the trap in place for anyone who passes `--trials`.
