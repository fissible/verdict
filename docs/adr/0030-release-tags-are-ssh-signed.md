# ADR 0030: Release tags are SSH-signed

Status: Accepted

## Related issues

- [#254](https://github.com/fissible/verdict/issues/254) item 4 is the decision this records. The
  issue framed the choice as GPG vs Sigstore `gitsign` vs deciding signing is not worth its cost, and
  asked that whichever way it lands be recorded — *the reasoning matters more than the signature.*

## Context

Verdict's audience is a security-conscious team evaluating a dependency, and its posture is already
strong: Actions pinned by commit SHA, weekly Dependabot, private advisory reporting, and — as of
#254 items 1–3 — an OpenSSF Scorecard workflow and posture badges. Signed tags are the remaining
signal.

Two facts bound the benefit, and neither is negotiable by wanting the signature more:

- **Packagist and Composer do not verify git tag signatures.** Nothing in the install path checks a
  signature, so signing buys **git-level** tamper-evidence only — value for a person who clones the
  tag and checks it, not verification of the installed package. SLSA-style artifact provenance would
  be the tool for the install path, and it stays out of scope (the issue) until releases produce
  artifacts beyond a git tag.
- **A signature is worth having only if the signing key's lifecycle is actually managed.** An
  unmanaged key is worse than none: it rots, and a rotation nobody performs turns "Verified" into a
  false assurance.

## Decision

### 1. Release tags are signed with SSH, reusing the key already managed for git

Releases are cut as `git tag -s` under git's SSH signing backend (`gpg.format=ssh`,
`user.signingkey` = the maintainer's existing SSH key). GitHub renders the tag **Verified** once
that key is registered as a *signing* key on the account. No new long-lived secret is introduced:
the key whose lifecycle must be managed is the one the maintainer already manages to push over SSH.

### 2. An unsigned release cannot be cut silently

`release.sh` refuses to proceed unless SSH tag signing is configured — the same defensive-preflight
posture as its `VERSION` / changelog / clean-tree checks. A misconfiguration fails the *deploy* with
a setup message, rather than producing an unsigned `vX.Y.Z` that reads as a normal release. The
release procedure, not this ADR, is the enforcement point; the config is a one-time maintainer setup
(`gpg.format=ssh`, `user.signingkey`, and adding the public key to GitHub as a signing key).

### 3. Rejected alternatives

- **GPG signed tags** — the same GitHub "Verified" outcome, but a *second* long-lived key to
  generate, protect, back up, and rotate. SSH dominates it here precisely because the SSH key's
  lifecycle is already carried; GPG would add the exact key-management cost #254 flags for no
  additional assurance.
- **Sigstore `gitsign`** — keyless, so no key to manage at all, with a Rekor transparency-log proof.
  Rejected for *this* repo because it is **not** GitHub-"Verified" by default and shifts verification
  onto `cosign`/`gitsign` tooling and trust in the Sigstore infrastructure — heavier for the "people
  who check" than reusing a key GitHub already understands. It remains the better answer if this repo
  ever wants keyless provenance without a maintainer-held key; that would be a new ADR.
- **Defer / do not sign** — rejected because the usual justification ("not worth the key cost") does
  not hold once the key already exists: the marginal cost of SSH-signing an annotated tag is a
  one-time `git config` and near-zero per release, against a real (if modest) git-level assurance.

## Consequences

- `release.sh` now cuts `git tag -s` and preflights the signing config; cutting a release requires
  the one-time SSH-signing setup on the maintainer's machine. Only the maintainer cuts releases, so
  the setup lives with them, not with contributors or CI.
- The claim this signature supports is scoped and must stay scoped: **git-level tamper-evidence for
  the tag**, not verification of what Composer installs. A README or security-doc line about signed
  releases must not overstate it into supply-chain verification the install path does not perform.
- This is a Verdict decision. It changes only Verdict's copy of `release.sh`; the fissible template
  and sibling repos are unaffected unless they adopt the same ADR.
