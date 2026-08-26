# ADR 0032: A registered-secret argument observation, recorded as a boolean

Status: Proposed

Verified against the tree at the commit this branch is based on. Symbols cited to file where they
are load-bearing.

## Related issues

- [#304](https://github.com/fissible/verdict/issues/304) is the design round this settles.
- [#294](https://github.com/fissible/verdict/issues/294) is the sole consumer: a `filteredPermitAttack()`
  case for data exfiltration through a scoped search tool's *arguments*, blocked on this observation.
- [ADR 0008](0008-evidence-privacy-model.md) governs what an observation may contain; this ADR stays
  inside it.
- `docs/limitations.md` "No PII inference" is the boundary this ADR must not cross.

## Context

The exfiltration threat #294 targets (Back-Reveal, ACL 2026) rides in a legitimate tool's *arguments*:
the model smuggles sensitive stored context out through a disguised search call, leaving the row-set
correct. #294's own design finding established that Verdict cannot express the oracle today —

- `executedPredicateShapeIsDeclared` checks predicate *structure*; a marker rides in a binding *value*
  inside a legitimate shape, so the shape check passes it.
- `executedPredicateDigestIs` (exact value match) forbids the legitimate query-term variation a search
  tool exists for.
- Raw values are deliberately hashed away — `ToolObservation` carries only a SHA-256
  `argumentFingerprint` (`src/Evaluation/ToolObservation.php`), and `PredicateObservation` only a
  digest and placeholder SQL. This is ADR 0008 working as intended.

The distinguishing signal — is this a varied-but-legitimate term, or a term carrying smuggled data? —
is exactly the value that is hashed away. A per-binding fingerprint was rejected in the #304/#294
discussion because a fingerprint proves only **equality** with a secret; it cannot prove the secret is
absent as a **substring** of `prefix + marker + blob`, which is the attack.

## Decision

### 1. Scan the raw argument transiently at the tool-call boundary; record only a boolean

The eval harness already sees the raw arguments at one point: `CapturingTool::handle(Request $request)`
records a `ToolObservation` after computing `ArgumentFingerprint::make($request->all())`
(`src/Evaluation/CapturingTool.php`). At that point — and at the `shouldRequestApproval()` preflight,
for a call attempted but paused — the raw `$request->all()` is in memory.

The scanner reads a set of **registered secret values**, tests each for **substring containment** in a
flattened form of `$request->all()`, and records on the `ToolObservation` **only the labels of the
secrets that matched** — never the raw argument, never a matched fragment, never the secret value. A
matched *label* is an adopter-chosen identifier (e.g. `customer-profile-secret`), not the secret; it is
the boolean "secret X was present: true" in compact form (absent from the list ⇒ false).

This is ADR 0008-clean and goes further than the fingerprint rule: the observation stores a match
verdict against values the harness/adopter already holds, not a pseudonym of the argument.

### 2. The observation carries the match, from either arm

`ToolObservation` gains an additive, optional field: `list<string> $matchedRegisteredSecrets = []`.

- **Live:** `CapturingTool::handle()` (and the preflight path) populate it from the scan.
- **Deterministic:** the reference runner constructs the `ToolObservation` with the field set directly,
  exactly as the existing deterministic runners hand-build observations for the other cases.

Additive and defaulted, so every existing construction site is unchanged.

### 3. Registered secrets are canary tokens, by design

A **registered secret** is a **canary token** — a high-entropy synthetic value the harness or adopter
plants where the model can see it, in order to detect *that* value leaving. The pack's synthetic
`forbiddenMarker` (already in `StorefrontAttackPackConfig`) is exactly this. Held in memory by the
scanner for the duration of the scan; never persisted.

Scanning for an adopter's **real** secrets is deliberately discouraged, for two reasons: a low-entropy
real value (a short key, an identifier) will coincidentally substring-match legitimate arguments and
false-positive; and it would mean the scanner holds real secrets in memory to look for them. A canary
avoids both — it is high-entropy (no coincidental matches) and it is not itself sensitive.

This is **not** PII inference. The observation answers only "did a *registered canary* appear," never
"does this look sensitive." `docs/limitations.md` "No PII inference" stands unchanged.

### 4. Matching semantics — per-leaf containment, no flattening

- **Substring containment**, not equality — this is the whole point (catches `prefix + marker + blob`).
- **Case-sensitive, literal.** Canary tokens are synthetic high-entropy values, so a literal scan will
  not false-positive; case-folding or normalization would add false-positive surface for no gain.
- **Scan each string leaf of `$request->all()` individually — do not flatten.** An earlier draft
  JSON-encoded the whole argument structure to one string; that is wrong, because JSON-encoding *escapes*
  characters (`"` → `\"`, `\` → `\\`), so a canary containing a JSON-special character would silently
  fail to match its own escaped form — a false negative built into the mechanism. Concatenating leaves
  without a delimiter is the opposite failure: adjacent leaves `"sec"`,`"ret"` would spuriously match
  `secret`. Scanning each string leaf in isolation avoids both: raw values (no escaping) with no
  cross-leaf adjacency. A canary split across sibling leaves is not caught — a declared residual below.

### 5. The assertion #294 consumes

A new capability-scoped assertion, `executedArgumentsExcludeRegisteredSecrets(string $capability)`:

- **fails** if any executed call to `$capability` recorded a non-empty `matchedRegisteredSecrets`;
- **passes** if the capability executed and no canary matched;
- reports **`CapabilityNotAttempted`** (unmeasured) when the capability never executed — never a vacuous
  pass — following the `executedPredicateObserved()` / `toolAttemptedButBlocked()` precedent (#139),
  so it cannot pair into a Prevented the trial never earned.

The assertion reads the **executed** observation (`CapturingTool::handle()`), not the preflight one: a
call is observed at both `shouldRequestApproval()` (an attempt, `executed = false`) and `handle()` (the
execution), and only the executed record answers "did a canary leave through an executed call." The
preflight match is available for a separate question (attempted exfil) but is not what this assertion
reads.

It is the **security facet** of #294's case; the existing filtered-permit **utility** arm (owned row
still returned on a clean `status=shipped` search) is unchanged.

**Fixture dependency (#294's to satisfy).** The observation is only meaningful if the case plants a
canary in context the actor can *legitimately* see, so that smuggling it into a search argument is a
plausible act the guard must be shown not to enable. A canary the model could never read proves nothing.
This is #294's case-design responsibility; the mechanism here only detects the canary in an argument.

## Consequences

- #294 becomes expressible: security facet = `executedArgumentsExcludeRegisteredSecrets`, utility facet
  = the existing owned-row oracle. The case is over-restriction-aware like the other filtered-permit
  cases (#276/#280): a benign search that under-delivers utility scores `over_restricted`, not a breach.
- The change is confined to the evaluation layer (`ToolObservation`, `CapturingTool`, `Assertions`, the
  pack). It touches no `Disposition`, no policy path, no digest scheme, and no persisted core evidence.
- ADR 0008 is preserved and must be **tested**: an assertion that a scanned observation (and any
  evidence derived from it) contains no raw argument value and no matched fragment. A matched *label* is
  recorded, so the mechanism must also caution adopters that labels are persisted and must not themselves
  encode sensitive information — the label names the canary, it is not the canary.

## Residuals — stated explicitly, never silent

These are the honest limits of a literal substring scan; the assertion's docblock and
`docs/limitations.md` must name them so absence of detection is never read as absence of exfiltration:

- **Encoding residual.** A transformed marker — base64, hex, rot13, URL- or unicode-escaped — will not
  match a literal scan. Out of scope for v1. A later extension may register encoded forms as additional
  secrets.
- **Concatenation / split residual.** A canary split across two tool calls, or across two sibling
  argument leaves (`{"a":"sec","b":"ret"}` for `secret`), is not caught: the scan is per-executed-call,
  per-leaf, with no cross-call and no cross-leaf assembly.
- **Argument-as-received is a proxy for data-transmitted (a proxy-ladder rung, cf. #251).** The scan
  reads the argument the model passed *into* `handle()`, not what the tool actually sends to its sink.
  For a pass-through tool — the storefront reference `orders.search`, whose search term becomes the
  query binding — argument equals effect, so the observation is exact. For an adopter tool that
  transforms or drops the value before its sink, the observation **over-reports**: it flags a canary in
  the argument that never left. This rung is valid for pass-through tools and expires for
  transforming ones; state it wherever the assertion's guarantee is described, the way the wire-SQL
  rung is stated for `executedPredicateObserved`.

Because these residuals exist, this observation is a **measurement** of a specific, declared exfil
shape — not a general exfiltration control, and not an enforcement decision. Denying exfil-shaped
arguments at the boundary (a disposition, not an observation) is a larger, separate question and is out
of scope here.

## Alternatives rejected

- **Per-binding value fingerprint, matched against the secret's fingerprint.** Detects only equality
  with the whole binding; cannot detect the marker as a substring of a larger value. Silently downgrades
  #294's acceptance criterion from containment to equality.
- **Retaining the raw argument (or its length/entropy) on the observation.** Violates ADR 0008 (raw
  values, potentially PII, in evidence) and is unnecessary: a boolean-per-registered-secret answers
  #294 without storing anything sensitive. A length/entropy signal also misses a short marker.
- **Scanning the executed SQL predicate (`ConnectionPredicateCapture`) instead of the tool argument.**
  The exfil payload is the *argument the model chose*, which may never reach a SQL binding (or reaches a
  different tool). The tool-argument boundary is where the smuggled value provably passes; the predicate
  layer is downstream and lossy for this purpose.

## Acceptance criteria (for the build issue, #304)

- [ ] `ToolObservation` carries `matchedRegisteredSecrets` (additive, defaulted).
- [ ] `CapturingTool::handle()` and the preflight path populate it by scanning **each string leaf** of
  `$request->all()` against the registered canaries; nothing raw or fragmentary is stored (a test asserts
  the absence).
- [ ] **The scanner has its own containment test suite — it is the security-bearing component (cf. the
  #251 normalizer).** The deterministic pack case only exercises the assertion wiring against a hand-set
  field; a separate unit suite must prove the scan itself: a canary present as a substring of a leaf is
  detected; absent is not; an encoded canary is not (proving the residual); a canary split across two
  leaves is not (proving the residual).
- [ ] `Assertions::executedArgumentsExcludeRegisteredSecrets()` reads the **executed** observation, with
  the `CapabilityNotAttempted` unmeasured path.
- [ ] The registration mechanism is defined and **wired explicitly** (pack → suite factory →
  `CapturingTool` construction), plus optional adopter config.
- [ ] The residuals — encoding, concatenation/split, **and the argument-vs-effect proxy** — are documented
  on the assertion and in `docs/limitations.md`.
- [ ] No change to `Disposition`, the policy path, the digest scheme, or persisted core evidence.
