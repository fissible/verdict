# ADR 0029: Approval challenge issuance is the measured fact

Status: Accepted

## Related issues

- [#204](https://github.com/fissible/verdict/issues/204) is the work this settles.
- [#195](https://github.com/fissible/verdict/issues/195) proved the approval-provenance claims
  deterministically; this ADR is what makes them measurable by the live harness, per case.
- [ADR 0008](0008-evidence-privacy-model.md) governs the opaque-identifier form `approved_by` and
  `rejected_by` already take; this ADR relies on that form rather than widening it.
- [ADR 0020](0020-live-trial-isolation-is-application-owned.md) — an unanswered challenge's pending
  receipt is trial state the application resets, on the same terms as everything else that ADR
  covers.
- [ADR 0024](0024-integrity-is-gated-before-coverage.md) — integrity is gated before coverage; this
  ADR treats a harness that cannot find a challenge it should be able to find the same way.
- [ADR 0026](0026-what-an-approver-is-shown.md) — the payload the runner observes is the one this
  ADR already governs the release of; this ADR does not open a second release path.

## Context

All three approval-provenance claims from #195 are proven deterministically, but the live harness
cannot measure any of them per case: `Observation` carries nothing about approval challenges, so a
live trial that hits a confirmation gate lands as `declined` or harness-blind `uncategorized`
(`docs/evaluation.md`, the "structurally unwinnable" passage), and the receipt issuance — payload
included — is invisible to every attack pack.

## Decision

### 1. Issuance is the measured fact

The runner observes that a challenge was issued and the payload it carried, at issuance. An
unanswered challenge is a legitimate terminal state: the run pauses, the trial ends, and the
pending receipt (with its past `expires_at`) is trial state the application resets per ADR 0020.
No auto-deny, no sweeper, no held-open trials. An auto-denied receipt would write a decision
nobody made into durable security state — `approved_by`/`rejected_by` carry an opaque identifier
for an authenticated reviewer, per ADR 0008, and the evidence-integrity story is the product.

### 2. The runner is an instrument, not an audience

Per ADR 0026, the payload's release to the approver audience already happened — or was refused —
by materialisation time. The instrument observes the result of the application's own release
configuration; a redacted or `Unknown` payload is a measurable fact ("the approver was shown
nothing"), not a gap. If the instrument's view travelled its own release route, a misconfigured
route would blind it silently — the negative-without-positive-control failure (#170). Challenge
facts are therefore assertion-only, mirroring `Observation::provenanceEntries`: never projected
into reports or baselines, and that containment is pinned by a test, not by omission (#247).
Recorded rule-if-ever: anything projected into reports or baselines must travel a release.

### 3. Blindness is a fault, never a negative

The preflight returning an `Approval` with no findable challenge is a harness-integrity error
(ADR 0024: integrity gates before coverage), never recorded as "no challenge issued."

### 4. Rejected alternatives

Auto-deny and answer-and-resume (the latter is a real future feature — confirmation fatigue,
post-decision behavior — with its own design surface; the observation vocabulary this decision
drives is shaped so it slots in without change). A core issuance event/observer (approach B): core
broadcasting a released payload to destinations the release policy never evaluated inverts ADR
0026's model; a pending-approval notification for applications is a separate product feature,
filed separately if wanted. The evidence-phase route (approach C: `ApprovalEvidencePhase::Issuance` + new `ClaimType`,
read via `LiveEvidenceReader`) is the named cross-process route if the harness ever spans
processes; not built now.

## Consequences

A live trial that hits a confirmation gate now produces a measured observation instead of landing
as `declined` or harness-blind `uncategorized`: `authorized-injected-action-requires-confirmation`
becomes live-winnable as-is, and `docs/evaluation.md`'s "structurally unwinnable" passage needs a
dated update note rather than a rewrite — the recorded-run narrative stays as observed.

Because challenge facts are assertion-only (decision 2), no report, baseline, or score changes
shape until a deliberate release is built for them. That is a limit as much as a decision: an
approver-facing summary of "N challenges pending" is not a side effect of this ADR and would need
its own design.

Because blindness is a fault (decision 3), an application whose framework-level
`approvalRequirement` bypasses Verdict's capture on a captured tool now fails loudly at
harness-integrity level instead of silently reporting an absence of challenges. That is intended:
an integrity failure that used to be indistinguishable from a clean negative result is now visible.
