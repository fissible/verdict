# Verdict glossary

**Inclusion rule:** an entry goes in only if the term is established outside this
repository and is actually used in `src/`, `docs/`, or an ADR. No aspirational
entries, and no entries for terms Verdict invented and uses once.

Each entry gives Verdict's meaning, where the term is used, and the competing
meaning that must not be assumed.

## Actor

The authenticated principal whose authorization is evaluated. In practice it is
the application identity passed through `ActionContext`.

Used in `src/Actions/ActionContext.php`, `docs/security-model.md`.

Competing meaning: any person or role. In Verdict it means the specific identity
the application chooses to authorize.

## Approval receipt

A durable record that a human approved a specific, bound action. It is consumed
before execution and cannot be reused for a different binding.

Used in `src/Approvals/`, `docs/security-model.md`.

Competing meaning: a generic confirmation. In Verdict it is a single-use,
canonically bound security state.

## Binding

The canonical, application-defined facts that scope a safeguard. Approval
bindings, claim bindings, and execution-target bindings use the same idea but
are not interchangeable.

Used in `docs/architecture.md`, `docs/security-model.md`.

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

Authority deliberately passed to another party under an explicit, attenuating
policy. It never silently expands the original grant.

Used in `docs/architecture.md`, `docs/security-model.md`.

Competing meaning: any transfer of work. In Verdict delegation preserves the
authority boundary and its attenuation invariant.

## Escalation

An increase in authority beyond the configured grant. It is never implicit and
must not happen through delegation.

Used in `docs/architecture.md`, `docs/security-model.md`.

Competing meaning: a harmless privilege bump. In Verdict escalation is a
security-relevant change that requires an explicit, controlled decision.

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

## Provenance

The recorded history of prompts and tool results, retained as structured facts
and fingerprints rather than raw content.

Used in `src/Evidence/ProvenanceLedger.php`, `docs/architecture.md`.

Competing meaning: general attribution. In Verdict provenance is a deliberate
audit trail with a privacy-first shape.

## Subject

The resource entity being acted upon, used interchangeably with the application
target in policy evaluation.

Used in `docs/architecture.md`, `docs/security-model.md`.

Competing meaning: the principal performing an action. Verdict uses actor for
that role and subject for the resource.

## Target

The application-selected resource that authorization, approvals, claims, limits,
and execution operate on.

Used in `src/Actions/ActionEnvelope.php`, `docs/security-model.md`.

Competing meaning: a destination. In Verdict the target is the trusted resource
the executor is allowed to act on.
