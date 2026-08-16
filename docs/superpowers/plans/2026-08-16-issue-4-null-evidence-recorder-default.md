# Issue 4 — Reconsider `NullEvidenceRecorder` as the shipped default

> Scoped 2026-08-16 against `main` @ `b17c713`. Issue body, not a plan to execute.

**Title:** `[Evidence] Decide whether a no-op recorder is the right shipped default`

**Labels:** `enhancement`, `scope: design`

---

## Problem

`config/verdict.php:36` ships:

```php
'recorder' => NullEvidenceRecorder::class,
```

A package whose value proposition includes evidence records **nothing** until an operator changes
that line. `docs/limitations.md:82` already discloses it in passing — *"`verdict.evidence.recorder`
itself defaults to `NullEvidenceRecorder`, a no-op, so nothing is recorded unless explicitly
configured"* — which is honest, but disclosure in a limitations file is not the same as a legible
default.

The consequence is silent. An application can register a capability with `requiresConfirmation()` or
`atMostOnce()`, run it in production, and have no record that any of it happened. Nothing errors,
nothing warns, and the absence is discovered when someone goes looking for an audit trail that was
never written.

## The case for the current default, stated fairly

This is not an obvious oversight, and the issue should not treat it as one.

- **Writing application data to a table the operator did not choose** is a real imposition. Evidence
  rows carry actor identity, capability names, argument fingerprints, and — depending on
  configuration — released context. Defaulting to on writes that data somewhere by default.
- **`DatabaseEvidenceRecorder` requires a migration.** A default that fails until migrations run is
  worse than a default that quietly does nothing, from a first-run perspective.
- The config comments around the evidence block already reason carefully about store selection, so
  the choice was considered rather than inherited.
- Under a fresh install with no configuration, a no-op recorder means Verdict's **authorization**
  still works. The security boundary does not depend on evidence — [ADR 0007](../../adr/0007-evidence-layering.md)
  is explicit that evidence is not an authorization gate, reinforced by #153.

## Threat model delta

None directly — evidence is not a gate. The delta is *forensic*: an incident in an application with
a no-op recorder is uninvestigable, and the operator may not learn that until the incident.

## Design argument

Three shapes, not mutually exclusive.

### (a) Change the default to `DatabaseEvidenceRecorder`

Honest about what the package is for. Costs: a migration becomes required for a working default, and
Verdict starts writing application data without an explicit decision. The migration cost is real but
bounded — `php artisan migrate` is already in the install path in `README.md`'s Installation
section.

The privacy cost is the harder one and should not be waved through. An adopter evaluating Verdict on
a staging database may not expect actor identifiers to be persisted by adding a package.

### (b) Keep the null default, make it loud at the moment it matters

A **startup or first-use warning when a capability with `requiresConfirmation()` or `atMostOnce()`
runs under a no-op recorder.** These two are the cases where the absence is most consequential: an
approval nobody can later prove was granted, and an at-most-once claim whose admission history is
unrecoverable.

This is the shape Verdict has repeatedly preferred — make the unsound configuration *visible* rather
than changing behaviour underneath the adopter. It is the same reasoning as ADR 0021's
`INSUFFICIENT` disposition: the machinery says the thing rather than leaving a human to notice.

Cost: warnings in a hot path need care not to become noise. Once per process, not once per action.

### (c) A `verdict:doctor` command

A single command that reports configuration that is legal but probably not what the operator wants:
no-op recorder, non-durable stores outside local, missing migrations. #146 already scopes a related
check for `verdict:validate` (warn on a non-durable adapter outside local), so there is a natural
home and this may be an extension of that rather than a new command.

Cost: a command nobody runs helps nobody. It is a complement to (b), not a replacement.

### Recommendation

**(b) plus (c), not (a).** The privacy argument against defaulting to a writing recorder is stronger
than the convenience argument for it, and Verdict's established posture is to make the gap visible
rather than to decide for the adopter. (a) can be revisited for 1.0, where a "secure by default"
stance is more defensible than during developer preview.

Whichever is chosen, the **production checklist item** is not optional: `docs/production-adoption-guide.md`
should name this explicitly rather than leaving it to `limitations.md`.

## Alternatives rejected

**Fail hard when a consequential capability runs with a no-op recorder.** Rejected: it turns a
forensic gap into an availability outage, and ADR 0007 is explicit that evidence must not gate
execution. Making evidence a precondition for action is exactly what #153 undid.

**Remove `NullEvidenceRecorder` entirely.** Rejected: it is legitimately correct for tests and for
applications that route evidence elsewhere via a custom recorder.

## Tests as spec

1. A capability with `requiresConfirmation()` executing under `NullEvidenceRecorder` **emits the
   warning exactly once per process**, not once per action. Mutation check: remove the
   once-per-process guard and confirm a test fails on the second action.
2. The same capability under a real recorder emits **no** warning.
3. A capability with neither `requiresConfirmation()` nor `atMostOnce()` emits no warning — the
   warning is scoped to where absence is consequential, not to every action.
4. `verdict:doctor` (or the extended `verdict:validate`) reports the no-op recorder, and exits
   non-zero only if that is the decided contract — **which this issue must decide**, since #146's
   existing warn-only posture suggests warn.

**Coverage note:** no attack pack case. This is a configuration-legibility issue, not a boundary
property, and inventing an attack case for it would be the vacuous-assertion pattern reviews in this
repo keep catching.

## ADR impact

**Amends [ADR 0007](../../adr/0007-evidence-layering.md)** if (a) is chosen — the default recorder is
a statement about evidence's role, and 0007 is where that role is defined. If (b)/(c) is chosen, **no
ADR**: making a configuration legible does not change a decision, and an ADR for it would dilute the
bar that keeps this repo's 22 ADRs worth reading.

## Documentation claims introduced

```
<!-- @verdict-claim evidence.null-recorder-warned tested -->
```

Testable under (b). Under (a) the existing `limitations.md:82` disclosure changes rather than a new
claim appearing.

## Dependency order

**Independent of the other three.** Can be taken at any point. Smallest of the four if (b) alone is
chosen.
