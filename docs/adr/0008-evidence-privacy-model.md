# ADR 0008: Evidence privacy model — fingerprints are pseudonymous correlation, not anonymization

Status: Accepted

## Related issues

- [#1](https://github.com/fissible/verdict/issues/1) (closed; delivered) implemented fingerprint-first provenance and evidence records.
- [#11](https://github.com/fissible/verdict/issues/11) (open) must preserve this privacy boundary in an attest-backed recorder.

## Context

Verdict's evidence design repeats a single privacy claim in at least five separate places, each time
scoped to the feature at hand rather than stated once as a general property:

- Provenance: "A hash of a predictable prompt, identifier, version, filename, URL, or personal value
  can be guessed and must be treated as correlation—not anonymization, encryption, or proof that the
  underlying input is safe." (README:765-767)
- Target-refresh evidence: "An unkeyed fingerprint is pseudonymous, not anonymous: low-entropy
  identifiers may be enumerable when an observer knows the input format." (ADR 0003, "Evidence"
  section)
- Rate limits: evidence "contains only the opaque bucket fingerprint plus the policy name, limit,
  remaining count, and reset time" (ADR 0001).
- Execution claims: metadata exposes `execution_claim_fingerprint` and
  `execution_claim_binding_fingerprint` (`src/ExecutionClaims/ExecutionClaimManager.php:127-135`),
  never the raw binding.
- General evidence: "The evidence store may contain highly sensitive information... A hash of
  predictable personal information is not anonymization." (README:713-715)

Each instance is correct and consistent with the others, but a contributor implementing a new
evidence-producing feature (or reviewing issue #11's attest adapter, which inherits this same
constraint) has no single place that states the model once. That makes it easy to accidentally regress
one feature's privacy posture while copying a pattern from another.

## Decision

Verdict's evidence privacy model is one property, applied uniformly:

1. **Every stored identifier that originates from potentially sensitive application data is a
   deterministic fingerprint (`ArgumentFingerprint`/`ContentFingerprint`, SHA-256), never the raw
   value.** This applies without exception to arguments, bindings, receipt IDs, target identities,
   and provenance content.
2. **A fingerprint provides correlation, not anonymization.** Two evidence rows with the same
   fingerprint are known to share the same underlying value; that is the fingerprint's purpose. It
   does not follow that the underlying value is unrecoverable. Low-entropy or guessable inputs
   (sequential IDs, short strings, common values) can be dictionary-attacked against a known input
   format even without reversing the hash function itself.
3. **Verdict does not keyed-hash, salt, or otherwise strengthen fingerprints against this by
   default**, because doing so would require Verdict to own and rotate a secret across every
   fingerprinted value, which is an application-level key-management decision, not a framework
   default. A future keyed-fingerprint adapter remains possible (ADR 0003 notes this explicitly) but
   is not implemented.
4. **This property is inherited by anything built on top of `EvidenceRecorder`**, including the
   attest-backed recorder in issue #11: signing and chain-linking (ADR 0007's "attestation" layer)
   make a fingerprint's *history* tamper-evident, but do not change whether the fingerprint itself is
   guessable. An attested evidence record with a guessable fingerprint is exactly as guessable as an
   unattested one.
5. **Evidence access, retention, and any future keyed-fingerprint adapter remain part of the
   application's security posture**, not Verdict's. Verdict's obligation is to never store the raw
   value in the first place; what an application does with the resulting evidence store (encryption
   at rest, access control, retention) is unchanged by this ADR and remains explicitly out of scope
   per README:713-715.

## Consequences

- New evidence-producing code must fingerprint any value that did not already pass through this
  model, rather than assuming "it's already hashed elsewhere so this is fine by association."
- Documentation for a new evidence-producing feature should link this ADR instead of restating the
  correlation-not-anonymization caveat in its own words each time, which is how the claim ended up
  duplicated five times.
- Issue #11's adapter design should cite this ADR directly: attestation strengthens integrity, not
  confidentiality, and the two must not be conflated in that issue's documentation.

## Alternatives rejected

### Add a keyed HMAC with an application secret as the default fingerprint function

This was considered implicitly by ADR 0003 ("a future keyed-fingerprint adapter remains possible")
and left undecided rather than adopted as a default, because it would make Verdict responsible for
secret provisioning and rotation for every application that installs it — a security-relevant default
Verdict cannot make safely on an application's behalf without that application's explicit
participation.

### Store raw values behind field-level encryption instead of fingerprinting

Encryption is reversible by design (that's its purpose), which does not satisfy the evidence design
goal of never retaining the raw value's plaintext at all, even to an operator with legitimate database
access. Fingerprinting is intentionally one-way; encryption is not a substitute for that property.
