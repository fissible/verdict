# Verdict glossary

**Inclusion rule:** an entry goes in only if the term is established outside this
repository and is actually used in `src/`, `docs/`, or an ADR. No aspirational
entries, and no entries for terms Verdict invented and uses once.

Each entry gives Verdict's meaning, where the term is used, and the competing
meaning that must not be assumed.

**Naming rules.** In Verdict's source, documentation, and ADRs:

- "Capability" always means the Verdict class. Where the object-capability sense
  is meant, write "object capability" in full. (ADR 0014 §4)
- "Delegation" is reserved for attenuating propagation of a principal's own
  authority. "Escalation" or "elevated approval" names the trusted-subsystem
  case. They are not synonyms. (ADR 0015 §4)
- `approvedBy` records who satisfied a condition, never who delegated authority.
  (ADR 0015 §4)

## Actor

The application-supplied principal whose authorization is evaluated, passed
through `ActionContext`. Verdict does not authenticate it, the same way it does
not authenticate `approvedBy`.

Used in `src/Actions/ActionContext.php`, `docs/security-model.md`, ADR 0015.

Competing meaning: any person or role. In Verdict it means the specific identity
the application chooses to authorize.

## Approval receipt

A durable record that a human approved a specific, bound action. It is consumed
before execution and cannot be reused for a different binding.

Used in `src/Approvals/`, `docs/architecture.md`.

Competing meaning: a generic confirmation. In Verdict it is a single-use,
canonically bound security state.

## Binding

The canonical, application-defined facts that scope a safeguard. Approval
bindings, claim bindings, and execution-target bindings use the same idea but
are not interchangeable. ADR 0013 separates three distinct layers — identity,
the authorization request, and runtime execution binding — which are frequently
conflated.

Used in `docs/architecture.md`, `docs/security-model.md`, ADR 0013.

Competing meaning: any association between two values. In Verdict a binding is
the deliberately chosen identity that a policy or receipt is tied to.

## Capability

A named permission with trusted target resolution, authorization, optional
safeguards, and an executor. Possession of the name confers nothing by itself.

Used in `src/Capabilities/Capability.php`, `README.md`.

Competing meaning: an unforgeable object-capability reference where possession
is authority. Verdict means the opposite: the application owns the authority.

## Claim

An execution-claim policy that admits one canonical operation at most once.
Claims carry retention and resolution behavior.

Used in `src/ExecutionClaims/`, ADR 0002, ADR 0009.

Competing meaning: a general assertion. In Verdict it is a strict at-most-once
admission identity.

## Delegation

Attenuating propagation of a principal's own authority: a delegated hop may
narrow scope but never add authority, broaden scope, or extend validity.

Verdict does not model multi-hop agent identity today. ADR 0015 states the
attenuation property in advance as Invariant D1, constraining any future
delegation model rather than introducing one.

Used in ADR 0015.

Competing meaning: any transfer of work. In Verdict delegation is specifically
the authority-preserving case, and it is not what an approval does.

## Escalation

Conditional exercise of a *different* principal's authority — typically the
application's own — with an approval as an input to the policy rather than a
source of authority. Verdict already expresses this: `requiresConfirmation()`
binds the approval to one concrete request and `atMostOnce()` bounds it to a
single execution. The Laravel gate decides against the business's rules, not the
approver's.

Nothing is attenuated here, so Invariant D1 does not apply. The approver is not
lending their permissions.

Used in ADR 0015.

Competing meaning: a principal gaining more authority than it was granted. In
Verdict nothing is widened; a second principal's authority is exercised under
conditions.

## Evidence

Recorded, structured security facts. Evidence documents what happened; it does
not by itself prove an action was safe.

Used in `src/Evidence/`, ADR 0007, ADR 0008.

Competing meaning: proof. Verdict's evidence is designed for audit and review,
not as a mathematical guarantee.

## Execution target

The trusted application resource selected by the resolver and used by policy and
execution. A `BoundTool` receives this target, not an arbitrary object from the
model.

Used in `src/Targets/ExecutionTargetPolicy.php`, `docs/architecture.md`.

Competing meaning: any object the model names. In Verdict the target is the
application-chosen resource.

## Fingerprint

A canonical hash of relevant structured facts. Verdict uses fingerprints in
evidence and claim identity so raw values are not persisted by default.

Used in `src/Evidence/ArgumentFingerprint.php`, ADR 0008.

Competing meaning: a biometric identifier. In Verdict it is a deterministic
security-state identity.

## Intent record

Write-once operational security state, committed before a guarded action's
mutating phase begins when the fail-closed lever is on. It is not evidence: this
row gates admission, and the evidence layer mirrors it afterwards. The outcome
record stays the sole authority on what happened, so an intent that no outcome
references is a gap signal rather than a completed action.

Used in `src/Intents/`, ADR 0007's Update (#160), `docs/incident-response.md`.

Competing meaning: what an actor meant to do — the sense in
[limitations](limitations.md#authorization-bounds-authority-not-intent), where
Verdict bounds authority rather than intent. An intent record makes no claim
about anyone's purpose; it records that an action was about to act.

## Provenance

The recorded history of prompts and tool results, retained as structured facts
and fingerprints rather than raw content.

Used in `src/Evidence/ProvenanceLedger.php`, `docs/architecture.md`.

Competing meaning: general attribution. In Verdict provenance is a deliberate
audit trail with a privacy-first shape.

## Subject

The principal on whose behalf an action is taken, as distinct from the actor who
takes it — RFC 8693's subject/actor split.

Verdict does not model this yet. `ActionContext` carries a single actor, so
evidence records that an approval was consumed but not on whose behalf. ADR 0015
names the gap and fixes the shape; the entry exists so the term is not reused
for something else in the meantime.

Used in ADR 0015.

Competing meaning: the resource being acted upon. Verdict calls that the target.

## Target

The application-selected resource that authorization, approvals, claims, limits,
and execution operate on.

Used in `src/Capabilities/Capability.php`, `docs/security-model.md`.

Competing meaning: a destination. In Verdict the target is the trusted resource
the executor is allowed to act on.
