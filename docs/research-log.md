# Verdict research log

A record of external research surveyed for its bearing on Verdict, and what Verdict
concluded about each item.

This log exists so that a decision made once does not get relitigated. When an idea is
rejected, the rejection and its reasoning are written down; when an idea is adopted, the
log records where it came from.

## How to read an entry

Each entry names one or more **primitives** — the transferable idea, stated independently
of the system that introduced it — and records a **verdict** for each:

| Verdict | Meaning |
| --- | --- |
| `already implements` | Verdict has this. The entry cites where. |
| `should adopt` | A real gap with a clear, in-scope fix. |
| `should investigate` | Plausible fit, but the design is not settled. |
| `intentionally rejects` | Considered and declined. The reasoning is the point. |
| `out of repo` | Belongs to `attest`, `attest-laravel`, or upstream Laravel AI. |

Verdicts cite a path in `src/`, an ADR, or a published doc. A claim that cannot be
grounded that way is recorded as `should investigate`, never as `already implements`.

**Candidate** lines carry a slug naming proposed follow-up work. Slugs are mapped to ADR
and issue numbers once that work is approved and filed.

Each section closes with a **Surveyed, no hook** line recording what was read without
producing an entry, so the breadth of the sweep stays visible.

---

## 1. Agent authorization and capability papers

### A Capability Kernel for Agent Authorization: Design and Threat Model — paper

Ali Pourrahim, June 2026. SSRN 6931639.

- Describes `authgate-kernel`, a minimal trusted computing base interposing a
  structural, deterministic authorization check between any decision-maker and any IO
  target. The kernel decides whether an action is *authorized*, explicitly not whether it
  is *wise*; semantic judgement is out of the TCB by construction.
- Argues that entangling authorization with the reasoning component is structurally
  unsound for three reasons: a promptable component cannot enforce a boundary, framework
  guardrails leave authority implicit and unauditable, and the set of decision-makers is
  not fixed, so an authorization layer tied to today's agent architecture ages badly.
- All authority lives in *capability proofs*: `subject_id`, `resource_hash`, a 64-bit
  rights bitmask, `expiry`, `epoch`, `issuer` (root or delegated), and an ed25519
  signature. There is no central registry in the TCB; the trust anchor is passed in by
  the caller rather than held in a singleton.
- Delegation is a signed chain. Validation walks leaf to root enforcing, at every node,
  signature validity, epoch floor, issuer-key-to-parent-subject binding, resource match,
  and **attenuation** — `child.rights ⊆ parent.rights`. Chain depth is bounded at 16 to
  prevent unbounded traversal.
- Revocation has two mechanisms. The primary is *epoch-based*: advancing `min_epoch`
  invalidates every proof issued in a prior epoch in O(1), with no revocation-list
  distribution. The secondary is an explicit root-signed revocation of a single proof
  hash. Only root-signed revocations are honored, so an attacker can neither forge a
  revocation nor mount denial-of-service by injecting garbage ones.
- The `verify` function is stateless — `verify(action, root_key, now) → Permit | Deny` —
  with no probability scores, no model invocations, and no network IO inside the gate. A
  canonical binding-hash gate rejects any action whose hash does not match its contents
  *before* any proof is parsed, giving tamper-evidence ahead of parsing.
- The integration surface is a JSON wire format, not a framework API, so the same kernel
  can sit under different decision-makers.
- Section 4 is the paper's most unusual contribution: a per-property verification-status
  table distinguishing what is machine-checked, what is *admitted* (axiom or `sorry`),
  and what is *pending* a tool the authors did not run. The authors explicitly correct
  their own repository badges, note six Lean "theorems" are tautological stubs, and
  refuse to report a Rust test count they could not reproduce. They make no blanket
  "formally verified" claim.
- Out-of-scope list is equally explicit: compromised trust root, key isolation, semantic
  manipulation upstream of the gate, clock and epoch integrity, side channels, and a
  Python reference layer that is not the verified TCB and may diverge from it.

**Primitive — structural vs. semantic authorization.** A security gate should answer
"is this authorized," never "is this wise," and semantic judgement should be outside the
trusted component.
**Verdict:** `already implements`. This is Verdict's founding split — "Models propose.
Applications authorize" (`README.md`), formalized as the boundary in
`docs/security-model.md` where a model proposes a capability and arguments while the
application resolves the target and evaluates policy. Useful as external corroboration,
not as new work.

**Primitive — caller-supplied time as part of the TCB.** When `now` is supplied by the
caller, a caller that supplies stale values defeats every expiry- and
revocation-dependent control. The paper lists this as an explicit non-goal.
**Verdict:** `should adopt`. Verdict has the identical exposure and does not document it.
`Clock` is a container singleton bound to `SystemClock` at
`src/VerdictServiceProvider.php:56` and is injected into `ApprovalManager`,
`RateLimitManager`, `ExecutionClaimManager`, `ContextReleaseManager`, and
`ProvenanceLedger`. Approval expiry is evaluated as `$time >= $this->expiresAt`
(`src/Approvals/ApprovalReceipt.php:28`) against that injected clock. An application that
rebinds `Clock::class` — including a test double leaking into a non-testing environment —
silently controls approval expiry, rate-limit windows, and claim retention. `Clock` is
part of Verdict's trusted base, and `docs/limitations.md` does not say so.
**Candidate:** `clock-trust-assumption`

**Primitive — honest verification-status accounting.** Publish, per claimed property, the
strongest evidence that actually exists, and mark the difference between specified,
partially checked, and verified.
**Verdict:** `should adopt`. `README.md` publishes six package-level guarantees and
`docs/security-model.md` publishes a five-item threat model, but neither maps a guarantee
to the test that demonstrates it. Verdict's tests are organized by unit
(`tests/Unit`, `tests/Feature`), not by guarantee, so a reader cannot check the claims and
a contributor can weaken one without an obviously failing test. Issue #20 already notes
that genuine concurrency tests are missing, which is one such gap. A guarantee-to-test
table is cheap, purely documentary, and directly raises the credibility of a pre-1.0
security package.
**Candidate:** `guarantee-test-traceability`

**Primitive — attenuating delegation chains.** Authority passed onward may only shrink;
attenuation is enforced at every node, not just at the leaf.
**Verdict:** `should investigate`. Verdict has no delegation or sub-agent model at all.
`ActionContext` carries a single actor (`src/Actions/ActionContext.php`) and
`CapabilityRegistry` is a flat name-to-capability map with no notion of one capability
granting a subset of another (`src/Capabilities/CapabilityRegistry.php`). Whether Verdict
should model sub-agent delegation is a genuine open question — two other papers in this
sweep raise the same gap — but the design is not settled and the answer may be "the
application owns this."
**Candidate:** `subagent-delegation-question`

**Primitive — epoch-based bulk revocation.** A monotonic epoch counter invalidates every
credential issued before it in constant time, with no revocation list.
**Verdict:** `should investigate`. Verdict's approvals expire individually via
`expiresAt`, and `ApprovalReceiptStatus` has no bulk-invalidation path. There is no way to
express "invalidate every pending approval issued before this incident." That is a
plausible operational need during a suspected prompt-injection incident, but it needs a
real use case before it earns surface area.
**Candidate:** `bulk-approval-invalidation`

---

### Securing Agents With Tracked Capabilities — paper

Odersky, Zhao, Xu, Bračevac, Pham (EPFL LAMP). CAIS '26.
ACM 10.1145/3786335.3813127. Local: `~/Downloads/3786335.3813127.pdf`

- Puts the agent in a programming-language safety harness: instead of calling tools, the
  agent emits code in a capability-safe language — Scala 3 with capture checking — and
  the type system statically tracks which capabilities each expression may use. Shipped
  as `tacit`, an MCP server, so any MCP-compatible agent connects without modification.
- Names the failure that per-call authorization cannot catch: "an agent might read a
  secret in one step and exfiltrate it in a later step, even if each individual step was
  permitted." Safety is a property of the *flow*, not of any single decision.
- The critique of sandboxing is precise and generalizes well beyond sandboxes: in the
  classified-leak scenario, every offending operation is individually permitted — the
  agent legitimately reads a private file and legitimately calls the cloud LLM. "The leak
  is the data flow from one to the other, which never crosses a sandbox boundary."
- `Classified[T]` wraps sensitive data and exposes `map(f: T -> U)` accepting only *pure*
  functions. A closure that captures a file or network capability has type
  `String ->{f} String` and fails to conform, so exfiltration is rejected at compile time.
  `reveal` requires a `CanAccess` capability the agent does not hold.
- Local purity is the load-bearing property. It requires capability safety (capabilities
  cannot be forged or forgotten) and capability completeness (capabilities regulate all
  safety-relevant effects), and it delivers a lightweight form of non-interference.
- Scoped capabilities give lifetime control: `requestFileSystem(root)(block)` creates the
  capability, passes it to the block, and invalidates it on return. The compiler rejects
  any closure or container that would retain it, because the block's return type cannot
  mention the capability.
- Two output channels: a normal channel the agent observes and which feeds the cloud LLM's
  context, and a secure channel delivered only to the human's terminal. Printing a
  `Classified[T]` sends `Classified(****)` to the agent and the real content to the user.
- Explicitly names *confirmation fatigue*: interactive approval prompts "cause confirmation
  fatigue and tend to be ignored in practice," and the paper treats reducing prompt
  volume as a design goal rather than a UX nicety.
- Dismisses separate policy DSLs — Bedrock AgentCore's Cedar policies "require
  coordinating two languages and offer little more than access lists," and cannot express
  that a sub-computation must be side-effect free.
- Treats the agent as adversarial in the system-security sense, and argues this is
  justified less by frontier-model malice than by scale: thousands of agents running
  business processes unsupervised make simple mistakes into vulnerabilities.
- Non-goals: correctness and hallucination, timing and termination side channels, and
  external processes that escape the language boundary (where the guarantee degrades to
  the underlying allowlist).

**Primitive — authorization of actions does not compose into control of flows.** A
sequence of individually authorized actions can accomplish something no single
authorization would have permitted.
**Verdict:** `should adopt` (as a documented limitation). This applies to Verdict exactly
as it applies to sandboxes, and it is the sharpest unstated gap found so far.
`ContextReleaseManager::release()` takes `Source`, `Trust`, and `DataClass` as
caller-supplied arguments and evaluates a single-hop predicate —
`ReleasePolicy::permits($source, $destination, $dataClass, $trust)` returns a bool from
those four values alone (`src/Context/ReleasePolicy.php`). The permitted payload is
returned as a plain array in `ContextReleaseResult::permitted()`, carrying no label. So
classification is asserted per release and does not propagate: data released as
`Sensitive` to one destination and later re-released through a different policy is not
recognized as the same data. Verdict performs *release control*, not *information-flow
control*. `docs/limitations.md` lists seven non-guarantees and this is not among them.
**Candidate:** `no-information-flow-control`

**Primitive — explicit projection over redaction.** Release an allowlist of named fields
rather than filtering a payload for things that look sensitive.
**Verdict:** `already implements`. `ContextReleaseManager::release()` projects an explicit
`$paths` allowlist through `FieldProjector`, and transformers are forbidden from widening
it — a transformer whose output introduces paths outside the projected set raises
`LogicException` (`src/Context/ContextReleaseManager.php`). This is the stronger design
and it is already the one in place.

**Primitive — asymmetric output channels.** The channel a model observes and the channel a
human observes need not carry the same content.
**Verdict:** `should investigate`. Verdict models release *destinations*
(`src/Context/Destination.php`) and channel *kinds* (`src/Context/ContextChannel.php`,
which enumerates `UserInput`, `RetrievedDocument`, `ToolResult`, `ApplicationContext`),
but every channel is an input to the model — there is no notion of a sink the human sees
and the model does not. Whether that belongs in Verdict or in the application's
presentation layer is genuinely unclear.
**Candidate:** `human-only-output-channel`

**Primitive — confirmation fatigue as a security property.** Approval prompts degrade with
volume; a design that emits many prompts is weaker than one that emits few, regardless of
what each prompt says.
**Verdict:** `should adopt` (as documentation). Verdict's `requiresConfirmation()` is
subject to this and the docs do not acknowledge it. `docs/security-model.md` explains that
approval is bound to canonical facts so a changed action cannot reuse it, which is a good
answer to approval *reuse* but not to approval *volume*. The guidance that follows from
the research is concrete and matches Verdict's existing stance that "a capability should
use the smallest set that adequately protects its real side effect": reserve confirmation
for genuinely consequential actions and prefer semantic limits for the rest.
**Candidate:** `confirmation-fatigue-guidance`

**Primitive — host-language policy over a separate policy DSL.** A policy language adds a
second language to coordinate and typically buys little over access lists.
**Verdict:** `already implements`. Verdict evaluates Laravel abilities through
`Capability::usingPolicy()` and `src/Policies/LaravelPolicyAuthorizer.php` rather than
introducing a rule DSL. The paper's critique of Cedar-based agent authorization is an
independent argument for the choice Verdict already made. Worth citing when the question
resurfaces.

---

### Governing Dynamic Capabilities: Cryptographic Binding and Reproducibility Verification for AI Agent Tool Use — paper

Ziling Zhou (Genupixel). arXiv:2603.14332v2 [cs.CR], March 2026.
Local: `~/Downloads/2603.14332v2.pdf`

- Proposes the *capability-context separation*: tool definitions determine which
  real-world actions are **possible** and change infrequently; runtime context determines
  which actions are **chosen** and changes per interaction. Inside a transformer's forward
  pass the two are indistinguishable token sequences, but at the orchestration layer they
  have entirely different security semantics.
- Derives three Agent Governance Requirements: G1 capability integrity (identity bound to
  the complete capability set, any change invalidating credentials), G2 behavioral
  verifiability (the agent executed the process it declared), G3 interaction auditability
  (tamper-evident records sufficient for forensic reconstruction).
- Identifies *silent capability escalation* as a vulnerability class: an agent acquires or
  modifies tools at runtime without triggering any existing security mechanism. Identity
  platforms bind agents to keys but certificates stay valid after capability changes;
  authorization frameworks evaluate permissions at grant time without detecting drift;
  runtime gateways inspect calls statelessly.
- Argues even hardware TEEs do not remove the need for G1 and G3 — a TEE-attested agent
  that silently acquires new tools is still a threat, and a TEE-attested pipeline without
  tamper-evident records is still unauditable.
- Instantiates G1 as a *skills manifest hash*: a SHA-256 commitment to the agent's
  complete tool configuration, embedded in an X.509 v3 extension within a trust
  propagation tree rooted at human principals.
- The Chain Verifiability Theorem: behavioral verification is a chain property — one
  unverifiable interior agent breaks end-to-end verification for every downstream node.
- The Bounded Divergence Theorem reframes replay-based verification as a probabilistic
  certificate (ε ≤ 1 − α^(1/n)), making software verification a graded approximation of
  hardware attestation rather than a different kind of claim.
- The interaction ledger stores only cryptographic commitments — agent IDs, certificate
  hashes, content commitments, reproducibility anchors — hash-linked with per-record
  signatures, so auditability does not require retaining content.
- Deliberately crypto-agnostic: G1–G3 specify functional contracts with pluggable
  primitives, demonstrated with a basic instantiation (Ed25519, SHA-256, hash chains) and
  an enhanced one (BBS+ selective disclosure, DV-SNARK).
- Positions the contribution as architectural rather than cryptographic, drawing the
  analogy to Certificate Transparency: the insight was identifying what to compose and
  where to deploy it, not inventing primitives.

**Primitive — commit to the capability configuration, not just the action.** Evidence about
a decision is incomplete unless it also identifies the configuration under which the
decision was made.
**Verdict:** `should adopt`. This is the strongest in-repo finding of the section.
`DecisionEvidence` records 25 per-decision fields but nothing that commits to the
capability's *configuration* (`src/Evidence/DecisionEvidence.php`). It stores policy
**names** — `targetPolicy`, `rateLimitPolicy`, `executionClaimPolicy` — not their content.
So if a capability's rate limit is changed from five per day to five thousand, or
`requiresConfirmation()` is removed, every subsequent decision record looks byte-identical
to the ones before. An auditor reading the evidence trail cannot tell which configuration
was in force. `CapabilityRegistry` is a plain in-memory array with no digest and no
change detection (`src/Capabilities/CapabilityRegistry.php`). Recording a capability
configuration fingerprint in `DecisionEvidence` is cheap, stays fingerprint-first in
keeping with ADR 0008, and closes a real forensic gap.
**Candidate:** `capability-configuration-fingerprint`

**Primitive — capability-context separation.** The set of actions that are possible and the
selection of which to take are different security concerns with different change rates and
different re-authorization requirements.
**Verdict:** `already implements`, partially. Verdict's capability registration is
application-owned and static — capabilities are registered by the application, and a model
can only propose against that fixed set (`src/Capabilities/CapabilityRegistry.php`,
`README.md`). Verdict is therefore not exposed to the paper's headline attack, in which an
agent acquires tools at runtime. But the separation is implicit; nothing in the docs states
that the capability envelope is deliberately not model-modifiable, and nothing detects a
change to it between deployments. The registry side of that is covered by
`capability-configuration-fingerprint` above.

**Primitive — commitment-only audit records.** Auditability and content retention are
separable; a ledger of commitments supports forensic reconstruction without storing
payloads.
**Verdict:** `already implements`. Verdict records fingerprints rather than raw content
through `src/Evidence/ArgumentFingerprint.php` and `src/Evidence/ContentFingerprint.php`.
ADR 0008 states the accompanying privacy limit that the paper does not: a fingerprint is
pseudonymous correlation, not anonymization, and a hash of a low-entropy or predictable
value may be enumerable. External corroboration for a settled decision.

**Primitive — hash-linked, signed, tamper-evident ledger.** Append-only records chained by
hash with per-record signatures.
**Verdict:** `out of repo`. Already tracked as issue #11, to be implemented as an
`EvidenceRecorder` backed by `fissible/attest`. ADR 0007 fixes the layering: attestation is
a property evidence can gain, not a new operational-state store or authorization gate. The
audit-systems section below revisits the topology question.

**Primitive — behavioral verifiability via replay (G2).** Re-execute with recorded inputs
and flag divergence beyond a declared threshold.
**Verdict:** `intentionally rejects`. Verdict does not observe or reproduce model
inference, and ADR 0012 already establishes that Verdict does not own token telemetry
while `docs/limitations.md` rules out provider-internal inspection. Replay verification
requires exactly the provider coupling both documents decline. Verdict's evaluation
harness (`src/Evaluation/LiveEvaluationRunner.php`) runs repeated trials against
thresholds for *testing* safeguards, which is a different activity from attesting that a
production inference occurred as declared.

---

**Surveyed, no hook.** Within these three papers: the Agent Governance Trilemma via Rice's
theorem (an impossibility argument, no design consequence for Verdict); BBS+ selective
disclosure and Groth16 DV-SNARK instantiations (cryptographic mechanism, and out of repo
under ADR 0007); the multi-provider inference determinism study (9 models, 7 providers)
and its 5.8× variance finding (bears on replay verification, which is rejected above);
Scala 3 capture-checking metatheory and safe-mode language restrictions (no PHP analogue —
PHP has no effect system, and this is a property of the harness language, not of an
authorization boundary); CaMeL's planner/parser split and the Dual LLM pattern (an agent
architecture, above Verdict's boundary); TEE attestation paths; and `authgate-kernel`'s
JSON wire format and TLA+/Lean/Kani artifacts.

## 2. Authorization systems

The question for this section is not whether Verdict should use these systems. It is
whether they contain ideas worth borrowing.

### Zanzibar — system

Pang, Cáceres, Burrows, Chen, Dave et al. "Zanzibar: Google's Consistent, Global
Authorization System." USENIX ATC '19.

- A globally distributed ACL store for Google Calendar, Cloud, Drive, Maps, Photos, and
  YouTube, serving millions of authorization requests per second against trillions of
  ACLs at 95th-percentile latency under 10ms.
- Authority is expressed as *relation tuples* — `doc:readme#owner@10`, or
  `doc:readme#viewer@group:eng#member` — with namespace configs defining userset rewrite
  rules that compute derived relations (an `owner` is also an `editor`, a folder's
  `viewer` propagates to child documents).
- The paper's central consistency contribution is naming the **new enemy problem**, which
  "can arise when we fail to respect the ordering between ACL updates or when we apply old
  ACLs to new content." It has exactly two variants:
  - *Example A, neglecting ACL update order.* Alice removes Bob from a folder's ACL, then
    asks Charlie to move new documents into that folder, where document ACLs inherit from
    the folder. Bob should not see the new documents but may, if the check neglects the
    ordering between the two ACL changes.
  - *Example B, misapplying old ACL to new content.* Alice removes Bob from a document's
    ACL, then asks Charlie to add new content. Bob should not see the new content but may,
    if the check is evaluated against a stale ACL from before his removal.
- Prevention requires two properties: *external consistency*, so causally related updates
  x ≺ y receive timestamps Tx < Ty, and *snapshot reads with bounded staleness*. ACLs are
  stored in Spanner, whose TrueTime assigns causally meaningful microsecond timestamps.
- The **zookie** is the client-visible mechanism: an opaque consistency token encoding a
  global timestamp, minted by a content-change check when a content modification is about
  to be saved, stored atomically alongside the content, and presented on subsequent
  checks. Given a content update at Tc, evaluating at a snapshot ≥ Tc guarantees every ACL
  update causally preceding the content update is observed.
- The protocol's *at-least-as-fresh* semantics are what buy the performance: Zanzibar may
  choose any timestamp fresher than the zookie's, which lets it serve most checks from
  already-replicated data at a default staleness and quantize timestamps to avoid hot
  spots. Always evaluating at the latest snapshot would require global synchronization
  with high-latency round trips and reduced availability.
- Evaluation happens at a single snapshot timestamp across multiple database reads, so
  writes up to the check snapshot — and only those — are visible.

**Primitive — an authorization decision must not be staler than the state it authorizes.**
This is the transferable core of the new enemy problem, independent of Spanner, TrueTime,
and global replication.
**Verdict:** `already implements`, and better than the documentation suggests. Verdict's
bound path handles Example B directly. `VerdictManager::runBound()` refreshes the target
(`src/VerdictManager.php:182`) and then **re-runs authorization against the refreshed
target** — `$this->authorizer->decide($capability, $envelope, $refreshEvaluation->target)`
at `src/VerdictManager.php:192` — rather than carrying the proposal-stage decision forward.
When confirmation is required, the approval is also re-validated against that refreshed
evaluation at `ApprovalEvidencePhase::ExecutionValidation`
(`src/VerdictManager.php:200-210`) before being consumed. So Verdict does not apply an old
authorization to refreshed content. This is a security-critical ordering invariant that
currently exists only as the sequence of statements in one method. ADR 0003 covers target
freshness but does not state re-authorization as a requirement, and nothing would fail if a
contributor reordered these stages or reused the proposal decision.
**Candidate:** `reauthorize-after-refresh`

**Primitive — consistency tokens (zookies).** Carry an opaque token from content write to
authorization check so the check can bound its own staleness.
**Verdict:** `intentionally rejects`. Zookies exist because Zanzibar is a *separate,
globally replicated* ACL store whose replicas can lag the content store. Verdict has no
such split: `LaravelPolicyAuthorizer::decide()` calls
`$this->gate->forUser($envelope->context->actor)->inspect($capability->ability, $target)`
in-process, in the same request, against the target the application itself just resolved
(`src/Policies/LaravelPolicyAuthorizer.php`). Authorization and content are not two
replicated stores that can disagree, so there is no cross-store causality gap for a token
to bridge. Adding consistency tokens would add public surface with no
corresponding problem. Where cross-request causality does matter, it is the application's
transaction and locking responsibility, which `docs/limitations.md` already assigns.

**Primitive — relationship-based access control.** Model authority as tuples over a graph
of subjects, relations, and objects, with rewrite rules deriving transitive permissions.
**Verdict:** `intentionally rejects`. Verdict deliberately does not own the relationship
model; `Capability::usingPolicy()` names a Laravel ability and the application's policy
decides. `docs/limitations.md` states this as "no replacement for Laravel authorization or
domain rules." Introducing a tuple store would mean maintaining a second, competing source
of authorization truth alongside Laravel policies — the failure mode ADR 0007 guards
against for evidence, applied to authorization.

---

### Cedar and Amazon Verified Permissions — system

AWS. `docs.cedarpolicy.com`. Amazon Verified Permissions is managed Cedar.

- A purpose-built authorization policy language, deliberately constrained rather than
  general-purpose, with Amazon Verified Permissions as the hosted service form.
- Decision semantics are three ordered rules: any satisfied `forbid` yields Deny;
  otherwise any satisfied `permit` yields Allow; otherwise Deny. **Default deny** is
  explicit — nothing is authorized without a specific `permit`.
- **Forbid overrides permit**, with no priority scheme, no specificity ranking, and no
  escape hatch. Cedar frames `forbid` policies as guardrails that `permit` policies cannot
  cross, understandable in isolation from any permit written now or later.
- **Policy order does not matter.** Each policy is evaluated independently and the results
  combined, so the outcome is identical regardless of storage or evaluation sequence.
- **Skip on error**: a policy that errors during evaluation drops out of consideration
  rather than forcing a denial. The stated rationale is availability — under deny-on-error,
  an application running fine on 100 policies could start denying everything the moment a
  broken 101st is added. Erroring policy IDs still surface in diagnostics.
- Validation is a **separate step from authorization**, expected to run "when policies are
  loaded or created," not during request evaluation. A schema supplies the entity types,
  attributes, hierarchy, and per-action principal/resource types that a policy alone cannot
  imply — Cedar cannot otherwise tell whether the author meant `User` or `Uzer`.
- Validation catches unrecognized types and actions, `in`/`==` confusion, unknown
  attributes, unguarded optional-attribute reads, and operator type mismatches; warnings
  flag conditions that always evaluate false, mixed-script identifiers, and bidirectional
  control characters.
- Validation soundness was **formally proved** with a validator built in Lean and
  automated reasoning, then differentially tested against the Rust implementation. The
  exceptions are enumerated rather than glossed: integer overflow, missing entities, and
  malformed extension-constructor arguments in non-strict mode.
- The authorization response names the **determining policies** — the satisfied permits
  when the answer is Allow, the satisfied forbids when Deny came from rule 1, and an empty
  list when Deny came from the default.

**Primitive — deny wins, and combining is order-independent.** No policy ordering, no
priority, no way for a grant to override a prohibition.
**Verdict:** `already implements`. Verdict's pipeline is structurally the same property
expressed as sequential stages: every stage must permit, and any stage that does not
short-circuits to a denied result. `runBound()` returns `ExecutionResult::denied(...)` at
each of proposal authorization, proposal-phase approval, target refresh, re-authorization,
execution-phase approval, rate limit, approval consumption, and execution-claim admission
(`src/VerdictManager.php:150-233`). Because every stage must pass, the conjunction is
order-independent in outcome even though it is ordered in execution. `docs/security-model.md`
already frames these as independent policies rather than "a hidden score or a single,
generic allow/deny rule."

**Primitive — skip on error.** An erroring policy is ignored rather than denying, so one
broken rule cannot take down an entire application.
**Verdict:** `intentionally rejects`, and the contrast is worth recording because Cedar's
reasoning is sound for Cedar and wrong for Verdict. Cedar optimizes for availability across
a large policy corpus where any single policy is a small part of the decision. Verdict
guards a small number of consequential side effects where a failed check means the security
question was not answered. ADR 0004 already requires mutating security-state operations to
fail closed on an unsafe outer transaction, and ADR 0006 defers streaming approval
resumption specifically because it must fail closed. A skip-on-error rule would contradict
both. Recorded here so the availability argument does not get imported later on Cedar's
authority.

**Primitive — validate policy wiring at load time, not at request time.** Configuration
errors should surface when configuration is loaded, not when a request depends on it.
**Verdict:** `should investigate`. Verdict currently validates one class of wiring error at
the worst possible moment. `Capability::execute()` throws `CapabilityNotExecutable` at
`src/Capabilities/Capability.php:243` when no `executeUsing()` executor was defined — and
that throw happens *after* target refresh, re-authorization, approval consumption, rate
limit consumption, and execution-claim admission have all already run
(`src/VerdictManager.php:230-233`). The claim is then marked indeterminate by the failure
handler at `src/VerdictManager.php:346-358`, so a pure wiring mistake consumes a rate-limit
slot, burns a human approval, and leaves an execution claim needing manual resolution
through `ResolveExecutionClaimCommand`.

The obvious fix — reject executor-less capabilities at registration — is wrong, and the
reason is worth recording. `VerdictManager::run()` accepts an external executor callable
(`src/VerdictManager.php:121-131`), which is the `GuardedTool` path ADR 0005 describes as a
bounded migration bridge. Capabilities used only through `run()` legitimately have no
`executeUsing()`. So executability is a property of the capability *paired with its usage
path*, not of the capability alone, and any check has to know which tool will invoke it.
An opt-in validation command or a `BoundTool` construction-time check both fit; the design
is not settled.
**Candidate:** `validate-capability-wiring-early`

**Primitive — enumerate the exceptions to a soundness claim.** Cedar proves validation
soundness and then names precisely the three cases the proof does not cover.
**Verdict:** `already implements`. This is the same discipline as `authgate-kernel`'s
verification-status table and as `docs/limitations.md`, which lists seven specific
non-guarantees rather than a general disclaimer. Reinforces `guarantee-test-traceability`
from section 1 rather than adding new work.

---

### Open Policy Agent and Rego — system

`openpolicyagent.org`. CNCF graduated project.

- A general-purpose policy engine that decouples policy decisions from enforcement:
  services query OPA with structured input and receive a structured decision, with policy
  authored in Rego and distributed as versioned bundles.
- **Decision logs** are the most transferable feature. Each event records the policy path
  queried, the input, the result, a `decision_id`, and — critically — `bundles[_].revision`,
  the revision of each policy bundle at the time of evaluation.
- OPA's own documentation states the reason plainly: because bundles are versioned and
  policy changes over time, knowing only the input and result is insufficient to explain an
  outcome, since the same input can yield different results under different revisions.
- `decision_id` is returned to the caller in API responses carrying a decision, so a live
  response can be tied back to its log entry.
- Redaction is *accounted for*, not silent: `erased` lists JSON Pointers to fields removed
  and `masked` lists pointers to fields modified, so a reader of the log knows what is
  missing rather than seeing an unmarked gap.
- `nd_builtin_cache` records input/output mappings for non-deterministic builtins,
  explicitly to support decision replay.
- Bundles are hot-reloadable, which is what makes revision-pinning necessary: the policy in
  force genuinely changes underneath a running system.

**Primitive — pin each decision to the revision of the policy that produced it.**
**Verdict:** `should adopt`. This is independent corroboration of
`capability-configuration-fingerprint` from section 1, arrived at from operations rather
than from cryptographic governance, and OPA states the argument in the form that applies
directly to Verdict. `DecisionEvidence` records policy *names* — `targetPolicy`,
`rateLimitPolicy`, `executionClaimPolicy` (`src/Evidence/DecisionEvidence.php`) — and those
names are stable across arbitrary changes to what the policies actually do. Two decisions
recorded a month apart under a rate limit that was silently raised from five per day to
five thousand are indistinguishable in the evidence trail. Verdict's capabilities are
registered in application code rather than hot-reloaded, which makes the drift slower than
OPA's but no more visible.
**Candidate:** `capability-configuration-fingerprint`

**Primitive — a caller-visible decision correlation identifier.**
**Verdict:** `already implements`. `ActionEnvelope` carries a UUID `id`
(`src/Actions/ActionEnvelope.php`) which is recorded as `DecisionEvidence::$envelopeId`
(`src/Evidence/DecisionEvidence.php`) and shared across every stage evaluation of a single
action, so all stages of one decision correlate and the caller holds the key.

**Primitive — account for redaction rather than redacting silently.**
**Verdict:** `already implements`. `ContextReleaseEvidence` records `requestedPaths`,
`releasedPaths`, and `transformedPaths` alongside the transformer names
(`src/Context/ContextReleaseManager.php`), so a reader can see which fields were requested,
which were actually released, and which a transformer altered. This is OPA's `erased`/
`masked` distinction, and Verdict's version is arguably stronger because the projector
enforces that a transformer cannot widen the released set.

**Primitive — the policy decision point as a separate process.**
**Verdict:** `intentionally rejects`. Verdict is an in-process Laravel boundary by design;
the executor runs in the same request as the decision. Externalizing the decision point
would introduce exactly the authorization-store-versus-content-store split that makes
zookies necessary in Zanzibar, and would put a network hop between authorization and
execution — widening the TOCTOU window ADR 0003 works to narrow.

---

### AuthZEN Authorization API — standard

OpenID Foundation AuthZEN working group. Version 1.0 reached Implementer's Draft; the
group's stated goal is a Final Specification, which this survey could not confirm has been
reached.

- Standardizes the wire protocol between a policy enforcement point and a policy decision
  point, drawing the split from XACML and NIST SP 800-162, so the two can interoperate
  "without requiring knowledge of each other's inner workings."
- The access evaluation request is a 4-tuple: `subject` (type, id, properties), `action`
  (name, properties), `resource` (type, id, properties), and optional free-form `context`.
- The minimal response is a single boolean, `{"decision": true}`. An optional `context`
  object may carry structured reasons — `reason_admin`, explicitly not for user display,
  and `reason_user` — comparable to XACML's advice and obligations.
- HTTP status codes signal protocol failures only. An authorization outcome, including a
  denial, is always returned with 200.
- Because many authorization systems hold no state, the enforcement point is expected to
  supply whatever attributes the policy needs.

**Primitive — a standard shape for an authorization question and answer.**
**Verdict:** `intentionally rejects` for the protocol, with the shape noted as convergent.
Verdict already models the same 4-tuple: the subject is `ActionContext`, the action is
`ActionProposal::$capability`, the resource is the application-resolved target, and the
context is `ActionEnvelope` plus `ActionProposal::$metadata`
(`src/Actions/ActionEnvelope.php`, `src/Actions/ActionProposal.php`). Implementing the HTTP
binding would mean becoming a network policy decision point, which the OPA entry above
rejects for the same reasons. The convergence is worth knowing — it suggests Verdict's
decomposition is the conventional one — but there is no consumer for the wire format inside
a Laravel application calling its own capability boundary.

One AuthZEN position is worth flagging as *not* transferable. The specification dismisses
concern about whether a PDP can trust enforcement-point-supplied values as "a misplaced
concern," reasoning that enforcement responsibility sits with the enforcement point anyway.
That reasoning does not survive the move to AI-triggered actions, where the untrusted party
is proposing the arguments. Verdict's entire target-resolution design exists because the
resource identifier arriving with a proposal cannot be trusted — the application resolves
the target from trusted storage rather than accepting the model's object reference
(`README.md`, `docs/security-model.md`).

---

**Surveyed, no hook.** **OpenFGA** (CNCF, open-source Zanzibar): contextual tuples,
the check/expand/list-objects API surface, and store/model versioning. Its ReBAC core is
covered by the Zanzibar entry and rejected for the same reason; its model-versioning
identifier is the same idea as OPA's bundle revision and adds nothing beyond it.
**Amazon Verified Permissions**: managed Cedar with policy stores and identity-source
integration — an operational packaging of Cedar with no distinct authorization primitive.
**XACML**: the PDP/PEP/PAP/PIP vocabulary AuthZEN inherits, plus rule- and
policy-combining algorithms; the combining algorithms are the Cedar entry's territory and
XACML's own are widely considered over-general. Also surveyed without hooks: Rego's
partial evaluation, Zanzibar's Leopard indexing system and tuple-storage sharding, and
Cedar's entity-hierarchy `in` operator.

## 3. Object-capability systems

These systems answer a question Verdict is in the business of answering: how do you safely
give software authority?

### The confused deputy — paper

Norm Hardy, "The Confused Deputy (or why capabilities might have been invented)."
ACM SIGOPS Operating Systems Review, 1988.

- A confused deputy is a program tricked by a less-privileged caller into misusing its own
  authority. It is a form of privilege escalation in which nobody explicitly changes any
  permission.
- The original scenario: a timesharing compiler let users name a file for debugging
  output. The compiler also kept usage statistics in `(SYSX)STAT`, so it held write
  permission across the `SYSX` directory — which also contained the billing records in
  `(SYSX)BILL`. A user invoked the compiler naming `(SYSX)BILL` as the debug output
  destination. The open succeeded, because the *compiler's* rights were applied rather
  than the user's, and the billing data was overwritten.
- The compiler is the deputy because it acts on the user's behalf; it is confused because
  it was manipulated into destroying a file the user could never have touched directly.
- Two ingredients are essential: the designator for the resource does not carry the
  authority needed to reach it, and the program's own permission is used implicitly. String
  filenames are incidental — the *separation* is the vulnerability.
- Capability systems avoid the class by binding designation and authority into one
  unforgeable thing. Had the client passed a file descriptor rather than a name, the
  compiler could not have named the billing file at all, because it held no capability to
  it.
- The article surveys an alternative: have the service act with the *client's* permissions
  rather than its own. It names the drawbacks precisely — it demands deliberate security
  effort from the server, and a careless server may simply skip it; it becomes complicated
  when the server is itself a client of another service; and it requires trusting the
  server not to abuse the borrowed rights.
- Modern instances include CSRF (the browser is the deputy, with cookies supplying ambient
  authority), the Samy worm, clickjacking (the *user* is the deputy), FTP bounce, and
  personal firewalls launching a browser to reach the network.
- On personal firewalls, it observes that prompting users about chained launches helps
  little, "since false positives are common and even sophisticated users grow habituated to
  clicking OK."
- Contemporary treatments now list AI agent delegation as an instance: an administrator
  authorizes an agent, which delegates onward to a second agent that was never vetted.

**Primitive — the confused deputy.** Authority misuse arising because a designation crosses
a trust boundary while the permission applied to it silently changes.
**Verdict:** `already implements`, and this is the clearest available framing of why Verdict
exists. An AI tool call is the confused-deputy setup exactly: the model is the
less-privileged party supplying a designation (`order_id` in `ActionProposal::$arguments`),
and the application executor is the deputy holding real authority. The naive
implementation — `refundOrder($modelSuppliedId)` — is Hardy's compiler verbatim.

Verdict takes the alternative Hardy describes, acting with the *client's* permissions:
`LaravelPolicyAuthorizer::decide()` evaluates the ability with
`forUser($envelope->context->actor)` against the application-resolved target
(`src/Policies/LaravelPolicyAuthorizer.php`), so the deputy's ambient authority is never
what admits the action. Verdict also refuses to accept the model's object reference,
resolving the target from trusted storage instead (`README.md`, `docs/security-model.md`).

What makes this worth recording is that Hardy's stated drawbacks of that alternative are,
one for one, the limitations Verdict already documents. "Demands deliberate security effort
from the server, and a careless server may skip it" is `docs/limitations.md`'s "a poorly
scoped target resolver or policy remains an application bug" and "no protection for
bypassed paths." "Complicated when the server is itself a client of another service" is
"no guarantee of downstream side effects." Verdict chose a known trade-off and documented
its known costs. The README's motivating example would land harder if it named the pattern.
**Candidate:** `confused-deputy-framing`

**Primitive — habituation defeats interactive prompting.** A third independent source, after
the tacit paper's "confirmation fatigue" and its own citation of the same effect.
**Verdict:** `should adopt` (as documentation). Reinforces `confirmation-fatigue-guidance`
from section 1. Three separate literatures converging on this is enough to justify saying
so in `docs/security-model.md` rather than leaving `requiresConfirmation()` to look like a
control that scales.

---

### The object-capability model — model

Dennis and Van Horn (1966); Mark Miller, *Robust Composition* (PhD thesis, Johns Hopkins,
2006). Realized in KeyKOS, EROS, CapROS, Coyotos, seL4; and in E, Joe-E, Caja, Monte.

- A capability is a transferable right to perform operations on an object. Wielding one
  requires an unforgeable reference plus a message naming the operation; the entire model
  rests on references being unforgeable.
- **Designation and authorization are the same act.** A reference unambiguously designates
  one object *and* confers permission to send it messages. There is no separate permission
  table consulted afterward — which is precisely why the confused deputy cannot arise.
- **No ambient authority.** Objects interact only by sending messages on references held,
  so no program has background privilege by virtue of who is running it. Language features
  that break this — assignment to another object's instance variables, reflection over
  object metadata, and the pervasive ability to import primitive modules such as
  `java.io.File` — are called out as *undeniable authority*.
- A reference is obtained in exactly four ways: initial conditions, parenthood (the creator
  of an object holds the only reference to it), endowment (a creator grants its child a
  chosen subset of its own references), and introduction (a party holding references to two
  others passes one to the other).
- **Only connectivity begets connectivity.** Authority propagates strictly along a
  preexisting chain of references. The practical consequence is that information-flow
  properties can be analyzed over the reference graph without reading object code, so
  guarantees survive the introduction of new and possibly malicious objects.
- **Attenuation** is the core pattern: from one reference, mint a proxy carrying
  restrictions — read-only, revocable — that vets messages and forwards only permitted
  ones. Restricted forwarders and revocable forwarders (caretakers) are instances.
- **Deep attenuation** applies a restriction transitively to everything reachable through
  the attenuated reference, typically via a *membrane*.
- The model aligns with ordinary object-oriented virtues: encapsulation, information hiding,
  and separation of concerns are the same discipline as least privilege. Tribble's analogy
  is handing a valet a car key while withholding the right of ownership — a distinction
  identity-based access control handles poorly when permissions shift dynamically.
- Some things labeled capabilities fall outside the model; POSIX capabilities are the
  standard counterexample.

**Primitive — designation combined with authorization in an unforgeable reference.**
**Verdict:** `intentionally rejects` at the target level, `already implements` at the tool
level, and the split is worth stating carefully because Verdict's vocabulary invites the
wrong reading.

Verdict's `Capability` is a **named policy bundle**, not an object capability. It is
identified by a string (`ActionProposal::$capability`), resolved through a registry that is
a plain name-to-object map (`src/Capabilities/CapabilityRegistry.php`), and it authorizes by
consulting Laravel's `Gate` against an actor identity — which is ambient authority in the
ocap sense. Designation and authorization are separate acts: the model supplies arguments
naming *which* order, and a policy check on the resolved object decides separately whether
that is allowed. In ocap terms this is an ACL system operated with discipline.

At the tool level, however, Verdict is closer to the model than it looks.
`AbstractVerdictTool::__construct()` takes `private readonly string $capability`
(`src/LaravelAi/AbstractVerdictTool.php`), so a `BoundTool` is welded to exactly one
capability by the application at construction. A model cannot mint a tool, cannot retarget
one at a different capability, and cannot invoke a capability for which it was handed no
tool. The set of tools an agent holds is granted by endowment and is not extensible by the
agent — which is genuinely "only connectivity begets connectivity," enforced by Laravel AI's
tool registration rather than by Verdict.

Both halves of that are true, neither is documented, and the name `Capability` points at the
half that is false. A contributor reading the ocap literature could reasonably propose
unforgeable capability handles as an alignment fix, when the actual design decision is that
Verdict delegates authority to Laravel's identity-based authorization on purpose. That
decision deserves an ADR.
**Candidate:** `capability-is-not-an-ocap`

**Primitive — attenuation and revocable forwarders.** Mint a restricted or revocable proxy
from a reference you hold rather than sharing the reference itself.
**Verdict:** `should investigate`. This is the third independent appearance of attenuation
in this survey, after `authgate-kernel`'s signed delegation chains and tacit's observation
that an outer agent can grant a sub-agent a subset of its own capabilities. Verdict has no
mechanism by which one capability yields a weaker one, and no sub-agent model at all. The
revocable-forwarder pattern is also the cleanest known answer to the bulk-invalidation
question raised in section 1, since revocation becomes switching off a proxy rather than
hunting down issued credentials. Reinforces `subagent-delegation-question` and
`bulk-approval-invalidation` rather than adding a third candidate.

**Primitive — least authority over least privilege.** Grant the narrowest authority that
still lets the work happen, rather than the narrowest permission on a fixed identity.
**Verdict:** `already implements` in guidance, if not in mechanism.
`docs/security-model.md` instructs that "a capability should use the smallest set that
adequately protects its real side effect," and `README.md` frames the safeguards as
independent choices per operation rather than a uniform bundle.

**Primitive — analyze the reference graph instead of the code.** Authority propagation is a
property of connectivity, checkable without reading implementations.
**Verdict:** `intentionally rejects`. There is no reference graph to analyze. Verdict's
authority relationships live in Laravel policies, which are arbitrary PHP, and in
application-supplied resolver and executor closures (`src/Capabilities/Capability.php`).
Static analysis of authority would require the capability-safe language discipline the tacit
paper depends on, which PHP does not provide and which Verdict cannot impose on host
applications. This is the same boundary already recorded against tacit in section 1.

---

**Surveyed, no hook.** **KeyKOS** and **EROS**: orthogonal persistence with system-wide
atomic checkpoints, and EROS's verified confinement mechanism — the checkpointing model has
no bearing on a request-scoped library whose security state is ordinary database rows
(ADR 0007), and confinement is the information-flow question already recorded under tacit.
**CapTP** and the E language's distributed layer: promise pipelining, third-party handoff,
and distributed revocation — Verdict holds no remote object references and passes no
capabilities across a network boundary, so none of the distributed-ocap machinery has a
counterpart. **seL4**: machine-checked kernel proofs, an assurance argument about an
operating system kernel rather than a transferable authorization primitive. Also surveyed:
sealer–unsealer pairs and rights amplification, Miller's membrane construction in detail,
Joe-E's and Caja's Java and JavaScript subsetting, and POSIX capabilities as a
counterexample to the model.

## 4. Distributed systems primitives

Verdict is not a distributed system. It is a request-scoped library in a single PHP process.
But it *coordinates* with things that are — databases, queues, payment processors — and the
primitives this literature developed for that coordination transfer directly.

### Fencing tokens — argument

Martin Kleppmann, "How to do distributed locking." 2016. Written as a critique of Redlock,
but the durable contribution is the fencing-token argument.

- A distributed lock is not a mutex: nodes and networks fail independently, so holding a
  lease is not the same as still holding it.
- The canonical failure: a client acquires a lease, is paused by a stop-the-world GC pause
  outlasting the lease, and writes anyway when it wakes. Another client legitimately
  acquired the lock meanwhile. HBase shipped this bug.
- You cannot fix this by checking lease expiry immediately before writing, because "GC can
  pause a running thread at *any point*, including the point that is maximally inconvenient
  for you." Page faults, network-backed disk reads, CPU contention, and a stray `SIGSTOP`
  produce the same effect, and network delay alone reproduces it without any pause at all.
- The remedy is a **fencing token**: "simply a number that increases (e.g. incremented by
  the lock service) every time a client acquires the lock," attached to every write.
- The token is useless unless the far end enforces it: "this requires the storage server to
  take an active role in checking tokens, and rejecting any writes on which the token has
  gone backwards." Client 1 wakes with token 33, the server has already processed token 34,
  and the stale write is rejected.
- Redlock's core flaw is that "it does not have any facility for generating fencing tokens" —
  its random value is not monotonic, and generating monotonic tokens across nodes would
  itself require consensus.
- Locks split into two purposes. For **efficiency**, a failed lock costs duplicated work.
  For **correctness**, it costs "a corrupted file, data loss, permanent inconsistency."
  The engineering advice differs sharply between the two.
- Safety must hold unconditionally under arbitrary pauses, delays, and wrong clocks; only
  liveness may depend on timing. Timeouts are guesses about failure, never proof of it.

**Primitive — the fencing token.** A monotonic counter issued at acquisition, carried on
every downstream write, and checked by the resource that receives it.
**Verdict:** `should adopt`. This is the sharpest finding in the survey so far, because
Verdict already computes a fencing token and then throws it away.

`ExecutionClaimManager` mints each claim with `id: Str::random(64)`
(`src/ExecutionClaims/ExecutionClaimManager.php:34`), and `DatabaseExecutionClaimStore`
keys claims by binding fingerprint, so re-claiming a released claim **reuses the same row
and increments the counter** — `'attempt_count' => $existing->attemptCount + 1`
(`src/ExecutionClaims/DatabaseExecutionClaimStore.php:135`). A stable identity plus a
counter that increases on every re-acquisition is a fencing token in Kleppmann's exact
sense, already durable in the database and already exposed on the `ExecutionClaim` value
object (`src/ExecutionClaims/ExecutionClaim.php`).

The executor never sees it. `AuthorizedAction` carries only `envelope`, `capability`, and
`target` (`src/Actions/AuthorizedAction.php`), and it is constructed from the *execution*
evaluation at `src/VerdictManager.php:232` — before `executeAfterRateLimit()` admits the
claim at all. The executor is then invoked as a bare `$executor()` with no arguments
(`src/VerdictManager.php:344`). So the one piece of code that talks to the payment
processor cannot learn the claim identity or attempt number that Verdict just computed on
its behalf.

The cost of this shows up in the documentation as an unavoidable-sounding limitation.
`docs/limitations.md` tells applications an execution claim "cannot guarantee exactly-once
completion in a payment processor, email API, queue, or remote system after the executor
begins," and instructs them to "design external integrations with idempotency keys."
Kleppmann's argument says the first half is permanently true — the resource server must
participate, and Verdict cannot make it. But the second half is work Verdict is currently
making applications redo, badly, from a value it is already holding. An application forced
to invent its own idempotency key will typically derive it from the model-supplied
arguments, which is exactly the untrusted input Verdict exists to distrust.
**Candidate:** `claim-identity-for-executors`

**Primitive — safety must not depend on timing; a lease checked before use can expire
during use.**
**Verdict:** `should investigate`, as documentation. Verdict validates approval expiry
against the injected clock (`ApprovalReceipt::isExpiredAt()` returns
`$time >= $this->expiresAt`, `src/Approvals/ApprovalReceipt.php:28`) at
`ApprovalEvidencePhase::ExecutionValidation`, then consumes the approval, admits the claim,
and only then runs the executor. Every one of those steps is a point at which the process
can pause. Kleppmann's argument is that no rearrangement closes this window — a receipt
valid at check time can be arbitrarily stale by the time the side effect lands.

That is inherent and not a defect. But `docs/limitations.md` documents the analogous window
for *targets* ("No complete TOCTOU protection") and says nothing about the same window for
*approval expiry*, which readers are likelier to assume is exact. Reinforces
`clock-trust-assumption` from section 1 rather than adding a separate candidate; the
approval-expiry window belongs in that ADR's scope.

**Primitive — locks for efficiency versus locks for correctness.**
**Verdict:** `already implements`. `atMostOnce()` is unambiguously a correctness lock, and
Verdict treats it that way: store failures are operational faults rather than denials
(ADR 0004), a failed executor marks the claim indeterminate rather than releasing it
(`src/VerdictManager.php:346-358`), and a failure to record even that outcome throws
`ExecutionClaimFinalizationFailed`. There is no fail-open path. The
`Claimed`/`Completed`/`Indeterminate`/`Released` state machine
(`src/ExecutionClaims/ExecutionClaimStatus.php`) preserves the distinction Kleppmann warns
against collapsing: "we do not know" is a distinct outcome from "it did not happen."

---

### Idempotency-Key HTTP header — standard

Jena and Dalal, `draft-ietf-httpapi-idempotency-key-header-07`, IETF httpapi WG, October
2025. Expired Internet-Draft, Standards Track intent. Deployed under this or an equivalent
name by Stripe, Adyen, PayPal, Square, Twilio, WorldPay, and Google Standard Payments.

- Defines a request header letting clients make non-idempotent methods "such as `POST` or
  `PATCH` fault-tolerant." The motivating case is a timed-out POST after which the client
  "is left uncertain about the status of the resource."
- The **client** generates the key. It is "a unique value generated by the client which the
  resource uses to recognize subsequent retries of the same request." UUIDs or similarly
  random identifiers are recommended.
- Keys must be unique and must not be reused with a different payload.
- The server may additionally compute an **idempotency fingerprint** from the request
  payload — a checksum over the whole payload or over selected elements — to judge whether
  two requests bearing the same key are genuinely the same request.
- Three server behaviors are specified. Unseen key: process normally. Retry after
  completion: replay "the result of the previously completed operation, success or an
  error." Retry while the first is still in flight: return a conflict error rather than
  reprocessing.
- Error mapping: 400 for a missing required key, 422 for a key reused with a different
  payload, 409 for a request still outstanding under the same key.
- Security considerations name two risks from weak keys: injection, when a server does a
  cache lookup without validating the client-supplied key, and data leaks, where
  low-entropy keys let attackers guess keys "and use them to fetch existing idempotent
  cache entries, belonging to other clients." Mitigations: publish a key format, validate
  against it, and use a composite lookup key mixing the client key with server-side
  attributes.
- Servers may require time-based keys so expired entries can be purged, and should document
  the expiration policy.

**Primitive — key plus payload fingerprint.** An opaque unique key establishes *which*
operation; a fingerprint over the payload establishes that a retry is genuinely the same
operation rather than a different one wearing the same key.
**Verdict:** `already implements`, in a form that matches the draft closely enough to be
worth naming. Verdict's execution claim is exactly this two-part construction: `id` is the
opaque key and `bindingFingerprint` is the payload fingerprint, and the store keys on the
fingerprint rather than the id (`findLockedByBinding`,
`src/ExecutionClaims/DatabaseExecutionClaimStore.php`). The draft's three server behaviors
map onto Verdict's outcomes: unseen fingerprint inserts and admits; a `Claimed` row denies,
which is the draft's 409; a `Completed` row denies, which is where the draft would replay.

Two differences are worth recording honestly. Verdict **does not store the output**, so it
cannot replay a completed operation's result the way the draft specifies — a duplicate is
denied rather than answered with the original response. That is the correct default for a
security boundary, where returning a cached side-effect result could itself leak, and it
should stay a deliberate difference rather than drift into a gap. Second, the draft's
low-entropy warning is already satisfied: `Str::random(64)` draws from PHP's CSPRNG, and
evidence records `hash('sha256', $claim->id)` rather than the id itself
(`src/ExecutionClaims/ExecutionClaimManager.php:130`), consistent with ADR 0008.

This standard also strengthens `claim-identity-for-executors` above. The draft's model is
that the *client* supplies the key — and when Verdict's executor calls Stripe, Verdict's
application is the client. Verdict holds a high-entropy, per-operation, binding-scoped
identifier that is precisely what the draft asks that client to send, and does not offer it.

---

### Transactional outbox — pattern

Richardson, `microservices.io/patterns/data/transactional-outbox.html`.

- Addresses the dual-write problem: "How to atomically update the database and send messages
  to a message broker?" Sending inside the transaction risks the transaction not committing;
  sending after it risks crashing before the send.
- "2PC is not an option. The database and/or the message broker might not support 2PC," and
  coupling a service transactionally to both is undesirable regardless.
- The service writes the outgoing message to an outbox table **in the same local
  transaction** as the entity updates, so a rollback discards both. A separate message relay
  — transaction log tailing or a polling publisher — forwards them.
- Guarantees that "messages are guaranteed to be sent if and only if the database
  transaction commits," and preserves application send order.
- Delivery is at-least-once: the relay may crash after publishing but before recording that
  it did. "A message consumer must be idempotent, perhaps by tracking the IDs of the
  messages that it has already processed."

**Primitive — atomic co-commit of a side effect's intent with the state change that
justifies it.**
**Verdict:** `intentionally rejects`, and the reasoning is one of the more interesting
contrasts in this survey. The outbox pattern says: put the auxiliary write *inside* the
business transaction so they commit together. ADR 0004 says the opposite —
`IndependentTransactionGuard::assertNoOuterTransaction()` throws `UnsafeOuterTransaction`
if any Verdict store mutation would run inside an application transaction
(`src/ExecutionClaims/DatabaseExecutionClaimStore.php`, and the approval and rate-limit
stores).

Both are right, because the failure modes are opposite. The outbox protects a message that
must *not* be sent if the business change rolls back. Verdict protects a claim that must
*still hold* if the business change rolls back — otherwise a rollback silently returns a
consumed approval or erases the record that an action was admitted, and the replay guarantee
evaporates after the application has already reported success. Sharing a transaction is
exactly the hazard ADR 0004 names.

The genuinely useful transfer is the *reason* the outbox works: it removes a distributed
agreement problem by co-locating the write. Verdict removes the same problem by requiring
independent durability instead. Neither reaches for 2PC. That symmetry is worth a sentence
in ADR 0004's alternatives, which currently rejects nested transactions and savepoints but
does not mention the pattern a reader coming from microservices literature will most likely
propose. Not worth an ADR of its own.

---

### Saga — pattern

Richardson, `microservices.io/patterns/data/saga.html`; originally Garcia-Molina and Salem,
1987.

- A saga is "a sequence of local transactions," each committing in its own database and
  triggering the next, used where a business transaction spans services and "2PC is not an
  option."
- Failure is handled by **compensating transactions** that explicitly undo already-committed
  work, coordinated either by choreography (each service publishes events others react to)
  or orchestration (a coordinator issues commands and reads replies).
- The stated drawbacks are the interesting part. There is no automatic rollback: "a
  developer must design compensating transactions that explicitly undo changes made earlier
  in a saga."
- And there is **no isolation** — the I in ACID is absent, so concurrent sagas can observe
  each other's intermediate states. Developers "must typically use countermeasures, which
  are design techniques that implement isolation," and "careful analysis is needed to select
  and correctly implement" them. (The page names no specific countermeasure; it defers to
  *Microservices Patterns* ch. 4.3. Not summarized here, since the source does not contain
  them.)
- Sagas need the outbox or event sourcing to atomically commit state and publish the next
  step.

**Primitive — compensation in place of rollback, at the cost of isolation.**
**Verdict:** `intentionally rejects` as a mechanism, `already implements` as an assignment
of responsibility. Verdict has no notion of a multi-step business transaction and no
compensation model; a capability is a single admitted side effect.
`docs/limitations.md` already places compensation with the application — "design external
integrations with idempotency keys, transactional outboxes, reconciliation, and compensating
operations where appropriate" — which is the right boundary. Verdict cannot know what
compensating a refund means.

The saga literature does supply the correct vocabulary for one thing Verdict already does:
an `Indeterminate` claim is the state a saga would need a compensating step to resolve, and
`ExecutionClaimResolution::Completed`/`Retryable`
(`src/ExecutionClaims/ExecutionClaimResolution.php`) is a human- or operator-driven
resolution rather than an automatic one. That is a deliberate and defensible choice, and
ADR 0002 covers it.

---

**Surveyed, no hook.** **Vector clocks** and **version vectors**: causality tracking across
replicas that may concurrently write. Verdict has one writer per request against one
database; there is no concurrent-replica divergence to order, and the causality question
Zanzibar raises was already answered in section 2 by in-process evaluation. **CRDTs**:
conflict-free merge requires that conflicting updates be *mergeable*, which is precisely
what a security decision must not be — two conflicting authorization outcomes must resolve
to deny, not to a join. **Consensus** (Paxos, Raft, viewstamped replication): Verdict's
atomicity comes from a single database's transactions and row locks, and introducing a
replicated log would mean owning the availability and operational burden that ADR 0004
explicitly declines. **Optimistic concurrency control**: `docs/limitations.md` already
assigns version checks and row locks to the application, and Verdict's target refresh is
deliberately a re-read plus re-authorization (section 2) rather than a compare-and-swap,
because Verdict does not own the write. **Leases** as a liveness mechanism, separately from
the fencing-token argument above. Also surveyed: two-phase commit and its blocking
coordinator failure mode, exactly-once as a delivery myth versus effectively-once as an
end-to-end property, and at-least-once plus idempotence as the practical substitute.

## 5. Database transaction research

Every TOCTOU discussion eventually lands here. Verdict's three durable stores are the only
places the package owns concurrency correctness rather than delegating it, so this is the
section where "the application owns transactions" stops being an answer.

### A Critique of ANSI SQL Isolation Levels — paper

Berenson, Bernstein, Gray, Melton, O'Neil, O'Neil. ACM SIGMOD '95.

- Argues the ANSI SQL isolation levels are defined by a list of prohibited phenomena that is
  both ambiguous and incomplete, and rebuilds the hierarchy on phenomena that can be stated
  as histories.
- Adds phenomena ANSI omits, including dirty write (P0), lost update (P4), read skew (A5A),
  and write skew (A5B), and shows the ANSI levels cannot distinguish real implementations
  such as Cursor Stability and Snapshot Isolation.
- Defines **Snapshot Isolation**: each transaction "reads data from a snapshot of the
  (committed) data as of the time the transaction started, called its Start-Timestamp,"
  and "is never blocked attempting a read." Updates by transactions active after that
  timestamp are invisible.
- Commit uses **first-committer-wins**: T1 "successfully commits only if no other
  transaction T2 with a Commit-Timestamp in T1's execution interval [Start-Timestamp,
  Commit-Timestamp] wrote data that T1 also wrote." This prevents lost updates (P4).
- **Write skew (A5B)**: "Suppose T1 reads x and y, which are consistent with C(), and then a
  T2 reads x and y, writes x, and commits. Then T1 writes y. If there were a constraint
  between x and y, it might be violated." As a history:
  `r1[x]...r2[y]...w1[y]...w2[x]`. The canonical example is a bank constraint where
  individual balances may go negative "as long as the sum of commonly held balances remains
  non-negative."
- First-committer-wins does not catch write skew, because the two transactions write
  *different* items. The constraint spans them; the write sets do not intersect.
- The paper's sharpest example for present purposes is a predicate-sum constraint: "a set of
  job tasks determined by a predicate cannot have a sum of hours greater than 8. T1 reads
  this predicate, determines the sum is only 7 hours and adds a new task of 1 hour duration,
  while a concurrent transaction T2 does the same thing." Because "the two transactions are
  inserting different data items (and different index entries as well, if any), this
  scenario is not precluded by First-Committer-Wins and can occur in Snapshot Isolation."
- Snapshot Isolation is nonetheless surprisingly strong — stronger than READ COMMITTED, and
  it precludes phantoms in the strict ANSI sense (A3) — which is exactly why it is dangerous:
  it passes the ANSI checklist while admitting A5B.
- Snapshot Isolation and REPEATABLE READ are incomparable: SI prohibits A3 but allows A5B;
  REPEATABLE READ does the opposite.

**Primitive — write skew.** Two transactions each read a consistent state, each write a
different item, each commit, and together violate a constraint neither violated alone.
**Verdict:** `already implements`, and the reason it is correct is a design decision that
currently looks like an implementation detail.

Berenson's 8-hour job-task example is structurally identical to a Verdict semantic rate
limit: a predicate-scoped count bounded by a threshold, with concurrent transactions each
reading a count below the limit and each adding one. The naive implementation — count the
matching action rows, compare to the limit, insert another — is that anomaly verbatim, and
it survives Snapshot Isolation.

`DatabaseRateLimitStore` does not do that. It materialises a **bucket row** keyed on
`(bucket_fingerprint, window_starts_at)` and locks that single row with `lockForUpdate()`
before reading `attempts` and updating it (`src/RateLimits/DatabaseRateLimitStore.php:57`).
This converts a predicate sum into a single-row read-modify-write, which turns A5B into
ordinary contention on one item — the case first-committer-wins and row locks both handle.
The disjoint-write-set condition that makes write skew possible is engineered away.

The remaining hole is the phantom: before the first bucket exists there is no row to lock,
so two transactions can both find nothing and both insert. Verdict closes it with the unique
index `verdict_rate_limit_bucket_window_unique`
(`database/migrations/create_verdict_rate_limit_buckets_table.php.stub:20-22`), catches
`UniqueConstraintViolationException`, and re-enters `consumeLocked()` with `mayInsert:
false` — with a comment stating the point exactly: "Another transaction created the first
bucket concurrently. Retry the actual consume operation so this caller is counted rather
than merely reading its row." `DatabaseExecutionClaimStore` uses the same construction
against `binding_fingerprint`, which the migration declares `->unique()`
(`database/migrations/create_verdict_execution_claims_table.php.stub:17`).

So the correctness argument for both limits rests on three things that must stay together:
one contended row per logical constraint, a unique index making concurrent creation of that
row impossible, and a lock-plus-retry path on constraint violation. None of that is written
down. A contributor optimizing the rate limiter to count evidence rows, or adding a limit
scope without the matching unique index, would silently reintroduce A5B — and it would pass
every single-threaded test. Issue #20 already covers adding concurrent-access coverage
(ADR 0004), but the *invariant* deserves stating, not just testing.
**Candidate:** `single-contended-row-invariant`

---

### PostgreSQL transaction isolation — reference

PostgreSQL documentation, "Transaction Isolation."

- Read Committed is PostgreSQL's default; internally only three levels exist, since Read
  Uncommitted behaves as Read Committed. PostgreSQL's Repeatable Read is stronger than the
  standard requires and does not allow phantom reads.
- Under **Read Committed**, `SELECT FOR UPDATE` on a row a concurrent transaction has
  updated waits, then re-evaluates the `WHERE` clause against the new row version and locks
  and returns that version. No error is raised.
- Under **Repeatable Read**, the same situation is fatal: if the first updater commits, "the
  repeatable read transaction will be rolled back with the message `ERROR: could not
  serialize access due to concurrent update`," because such a transaction "cannot modify or
  lock rows changed by other transactions after the repeatable read transaction began."
- The required response is unambiguous: "When an application receives this error message, it
  should abort the current transaction and retry the whole transaction from the beginning,"
  and "applications using this level must be prepared to retry transactions due to
  serialization failures."
- **Serializable** adds SSI monitoring on top of Repeatable Read, detecting read/write
  dependency cycles via non-blocking predicate locks (`SIReadLock`) and rolling one
  transaction back with `ERROR: could not serialize access due to read/write dependencies
  among transactions`. Its documented example is a write skew over two predicate sums.
- Both levels signal with **SQLSTATE 40001**, and the docs stress needing "a generalized way
  of handling serialization failures... because it will be very hard to predict exactly
  which transactions might contribute to the read/write dependencies."
- Under Serializable, "it is possible to see unique constraint violations caused by conflicts
  with overlapping Serializable transactions even after explicitly checking that the key
  isn't present before attempting to insert it."
- Snapshot stability is not consistency: "attempts to enforce business rules by transactions
  running at this isolation level are not likely to work correctly without careful use of
  explicit locks."

**Primitive — the isolation level is part of the concurrency contract, not an operator
detail.** The same `SELECT FOR UPDATE` blocks under one level and aborts under another.
**Verdict:** `should investigate`. Verdict's stores catch exactly one database exception —
`UniqueConstraintViolationException` — in `DatabaseRateLimitStore::consume()` and
`DatabaseExecutionClaimStore::claim()`. A serialization failure is not that exception, and
there is no retry loop.

Under PostgreSQL's default Read Committed, this is fine: `lockForUpdate()` blocks, re-reads
the updated row, and the counter logic sees the post-update `attempts`. Under Repeatable
Read or Serializable on the store connection, a concurrent claim or consume raises SQLSTATE
40001 and Verdict propagates it. ADR 0004 already establishes that "store exceptions remain
operational faults rather than ordinary model-visible denials," so this **fails closed** —
the executor is not admitted, and no security property is violated. The consequences are
that a legitimate second action surfaces an operational exception instead of a clean
rate-limit or replay denial, and that the retry the database is explicitly asking for never
happens.

The gap is that this contract is nowhere stated. Searching the repository for isolation
level yields one line, ADR 0003:353, and it is about the *application's* transaction, not
Verdict's stores. ADR 0004 tells operators to put Verdict stores on a separately committed
connection but says nothing about what isolation level that connection must use — and an
operator who has just been told to create a dedicated connection for security state is
precisely the operator who might reach for `SERIALIZABLE` on it, reasoning that stricter is
safer. Here, stricter converts denials into exceptions.

Two things follow, and the second is a genuine open question rather than a conclusion.
First, ADR 0004's operator guidance should state the assumed isolation level and the
consequence of raising it. Second, whether Verdict should catch SQLSTATE 40001 and retry —
the way it already retries on unique-constraint violation — needs to be settled against a
real database rather than argued from the documentation. **This must be validated
experimentally before it is decided.** The behavior of `lockForUpdate()` under MySQL/InnoDB's
default REPEATABLE READ is *not* asserted here, because it was not verified; InnoDB's
current-read semantics differ from PostgreSQL's and the difference is the whole question.
Verdict supports both drivers, so the matrix needs testing, not reasoning.
**Candidate:** `store-isolation-level-contract`

---

**Surveyed, no hook.** **Adya, Liskov, O'Neil**, "Generalized Isolation Level Definitions"
(ICDE 2000): implementation-independent definitions via serialization graphs, motivated by
the same critique; the finer-grained level definitions do not change any Verdict decision.
**Fekete, Liarokapis, O'Neil, O'Neil, Shasha**, "Making Snapshot Isolation Serializable," and
**Cahill, Röhm, Fekete**, "Serializable Isolation for Snapshot Databases" (the SSI work
PostgreSQL implements): read as background for the isolation-level entry above rather than
summarized here, since PostgreSQL's own documentation states the operational contract
Verdict actually depends on. **Two-phase locking** and **multiversion concurrency control**
as mechanisms. **`SELECT ... FOR UPDATE SKIP LOCKED`** as a queue-claiming idiom: not
applicable, because Verdict must contend for a specific binding rather than find any
available row. Also surveyed: the ANSI phenomena P0–P4 individually, Cursor Stability, and
the distinction between a transaction that is *atomic* and one that is *isolated* — Verdict
needs both from its stores, and ADR 0004 addresses only the first.

## 6. Provenance

Verdict records what happened. Provenance research studies why it happened. Verdict already
has a provenance subsystem, which makes this the section where the comparison is against
existing code rather than against an absence.

### W3C PROV — standard

W3C PROV Model Primer (and PROV-DM). W3C Recommendation, 2013.

- Provenance describes the origins of things, for uses including deciding "whether to trust
  it," determining ownership, verifying that a process complied with requirements, and
  reproducing how something was generated. It is metadata, but not all metadata is
  provenance — an image's size is not.
- Three core types. **Entity**: "physical, digital, conceptual, or other kinds of thing are
  called entities." **Activity**: "activities are how entities come into existence and how
  their attributes change to become new entities." **Agent**: "an agent takes a role in an
  activity such that the agent can be assigned some degree of responsibility for the
  activity taking place."
- To describe an agent's own provenance it must be declared both as an agent and as an
  entity — responsibility and origin are separate axes.
- The core relations are `wasGeneratedBy` (entity ← activity), `used` (activity → entity),
  `wasAssociatedWith` (activity → agent), `wasAttributedTo` (entity → agent),
  `wasDerivedFrom` (entity → entity, where one entity's "existence, content, characteristics
  and so on are at least partly due to another entity"), `wasInformedBy` (activity →
  activity), and `actedOnBehalfOf`, which expresses delegation — an agent "acting on behalf
  of others, e.g. an employee on behalf of their organization," letting chains of
  responsibility be expressed.
- Three complementary perspectives: **agent-centered** (who was involved),
  **object-centered** (tracing origins between artifacts), and **process-centered** (the
  steps taken). A complete record needs all three.
- Supporting notions: roles (application-specific, so PROV defines none), plans
  (pre-defined procedures an agent follows), and time.
- A **bundle** groups provenance assertions and is itself an entity, so provenance of
  provenance can be asserted — the primer's example is a blogger recording that she
  personally verified her sources.

**Primitive — provenance as a graph of entities, activities, and agents, not a log.** The
edges are the content; a timestamped list of observations is not provenance in this sense.
**Verdict:** `already implements` the agent- and object-centered halves, `intentionally
rejects` true derivation, and has a concrete, cheap gap in between.

Verdict's `ProvenanceEntry` maps onto PROV more closely than the naming suggests
(`src/Evidence/ProvenanceEntry.php`). The entity is the content, identified pseudonymously
by `contentFingerprint`. The agent is `source`, qualified by `trust`. The activity is
`channel` — `ContextChannel` distinguishes `UserInput`, `RetrievedDocument`, `ToolResult`,
and `ApplicationContext` (`src/Context/ContextChannel.php`), which is a process-centered
classification of how content entered. `componentLabel` and `componentFingerprint` are a
plan-and-version pair. `correlationId` is a bundle identifier. `recordedAt` is time. That is
a defensible PROV subset, and the constructor enforces its integrity — a component
fingerprint requires a component label, and every fingerprint must be a lowercase SHA-256
digest.

What is missing is `wasDerivedFrom`. Entries under one correlation are a flat,
timestamp-ordered list; nothing says the tool result was derived from the retrieved
document. **Verdict cannot supply that edge and should not pretend to.** Establishing that
an untrusted document actually influenced a model's tool call requires attribution inside
the model, which `docs/limitations.md` explicitly disclaims as "no provider-internal
inspection." Recording a derivation edge Verdict cannot observe would be worse than
recording none. This is a genuine `intentionally rejects`, and it is worth stating in those
terms rather than leaving the omission to look like incompleteness.

**Primitive — `actedOnBehalfOf`, delegation of responsibility between agents.**
**Verdict:** `should investigate`. Fourth independent appearance of the delegation question,
after `authgate-kernel`'s signed chains, tacit's sub-agent capability subsets, and the
object-capability attenuation patterns in section 3. PROV contributes the observation that
delegation is a *provenance* relation as much as an authorization one: the question "who is
answerable for this action" outlives the question "who was permitted to take it."
Reinforces `subagent-delegation-question`.

---

### Provenance and decision evidence are not joinable — finding

This is a finding about Verdict rather than an external source, but it emerged from applying
PROV's agent/activity/entity framing to the existing schema, so it belongs here.

Verdict records both halves of the question an AI security boundary most wants answered —
*what untrusted content was in the agent's context when this capability was authorized?* —
in the same table, and cannot join them.

`verdict_evidence` carries a single `correlation_id` column
(`database/migrations/create_verdict_evidence_table.php.stub:16`) discriminated by
`record_type`, and the provenance migration adds an index built precisely for correlation
queries: `['record_type', 'correlation_id', 'recorded_at']`, named
`verdict_evidence_provenance_correlation_index`
(`database/migrations/add_provenance_to_verdict_evidence_table.php.stub`).

The two record types populate that column from disjoint identifier namespaces:

- **Provenance** uses Laravel AI's invocation id. `RecordAgentPromptProvenance` records
  `correlationId: $event->invocationId`, `RecordToolResultProvenance` records the same
  (`src/LaravelAi/RecordToolResultProvenance.php:28`), and
  `VerdictProvenanceMiddleware` uses `$prompt->invocationId`. So prompt and tool-result
  entries for one agent run correlate to each other correctly.
- **Decisions** use the envelope id: `'correlation_id' => $evidence->envelopeId`
  (`src/Evidence/DatabaseEvidenceRecorder.php:29`), and `ActionEnvelope` mints
  `id: $id ?? Str::uuid()->toString()` (`src/Actions/ActionEnvelope.php:26`) — a fresh UUID
  per envelope, created inside `AbstractVerdictTool::envelope()`.

Nothing carries the invocation id across. `AbstractVerdictTool::envelope()` sets
`idempotencyKey: $request->toolCallId()` and `metadata: ['transport' => 'laravel-ai']`
(`src/LaravelAi/AbstractVerdictTool.php:151-158`); `ActionContext` holds only `actor` and
`metadata` (`src/Actions/ActionContext.php`); and `invocationId` appears nowhere in `src`
outside the three provenance recorders. An application can stuff it into
`ActionContext::$metadata` by hand, but the decision record's `correlation_id` remains the
envelope UUID regardless.

The consequence is specific. Verdict can tell you an untrusted `RetrievedDocument` with
fingerprint F entered invocation I, and separately that capability C was denied under
envelope E. It cannot tell you that E happened during I. Every investigative question that
motivates provenance in an AI system — did untrusted content precede this action, which
sources were present when this approval was requested, does this denial cluster with a
particular document — needs exactly that edge. PROV would call it `wasInformedBy`: not a
claim about causation, just about which activity a decision occurred within. That is a claim
Verdict *can* make honestly, unlike derivation, because it is a containment fact Verdict
observes directly.

The fix appears small — thread the invocation id onto the envelope and record it — but it
touches the evidence schema, the Laravel AI integration, and ADR 0007's layering, so it
needs a decision rather than a patch.
**Candidate:** `provenance-decision-correlation`

---

**Primitive — reproducible computation.** Re-run the recorded process and obtain the same
result, using provenance as the recipe.
**Verdict:** `intentionally rejects`, for a reason already settled. ADR 0008's
fingerprint-first model means evidence records SHA-256 digests rather than content, so no
decision can be recomputed from its evidence — only checked for equality against a
recomputed digest. That is the correct trade for a security boundary handling prompts and
tool arguments, and it is the same reasoning that makes `wasDerivedFrom` unavailable.

It does sharpen one existing candidate. Reproducibility asks whether the same inputs would
yield the same decision *today*, and Verdict cannot answer that, because `DecisionEvidence`
records `targetPolicy`, `rateLimitPolicy`, and `executionClaimPolicy` as *names* only, never
their configuration (`src/Evidence/DecisionEvidence.php`). A capability whose rate limit was
raised from 5 to 500 produces evidence indistinguishable from one whose limit never moved.
PROV's plan-and-version notion is the right framing, and `ProvenanceEntry` already has the
`componentLabel`/`componentFingerprint` pair for exactly this purpose on the context side —
it simply is not applied to capability configuration on the decision side. Reinforces
`capability-configuration-fingerprint` from section 2, and suggests the mechanism.

---

**Surveyed, no hook.** **PROV-O**, **PROV-N**, and **PROV-CONSTRAINTS**: the OWL ontology,
textual notation, and inference/validity rules. Verdict has no RDF surface and no reason to
grow one; the transferable content is the data model above. **Provenance in scientific
workflow systems** (Taverna, Kepler, VisTrails) and the older Open Provenance Model:
workflow-graph capture aimed at re-execution, which is the reproducibility question already
rejected. **Fine-grained data provenance in databases** — why/where/how provenance,
provenance semirings, and lineage in Trio: attributes an output tuple to input tuples
through *known query semantics*, which is precisely the property an LLM invocation lacks.
**Provenance-based intrusion detection** (CamFlow, whole-system provenance graphs, PROV-Tracer):
kernel-level capture feeding graph anomaly detection — a systems-monitoring posture rather
than a library primitive, and one whose graph is built from syscalls Verdict never sees.
Also surveyed: provenance bundles as signed units, and the distinction between provenance
*capture* and provenance *analysis*.

**Eval-driven development for agentic systems** (Airbnb Engineering, "Eval-Driven
Development: Lessons from Evaluating GenAI at Scale," Medium, 2025): argues multi-step AI
systems must be evaluated across intermediate tool calls and reasoning steps, not just final
outputs, by reconstructing execution traces — a correct final result can hide a wrong path.
That is an output-quality evaluation problem, not decision-audit provenance, and EDD's
rubric/LLM-judge/golden-dataset machinery has no Verdict analogue. But the underlying claim
is the same primitive `provenance-decision-correlation` is missing above: independent
validation, from a different problem domain, that linking a decision to the invocation it
occurred *within* — not just its own local fingerprint — is the right unit of analysis for
auditing multi-step LLM systems.

## 7. Audit systems

Attest already shares DNA with this ecosystem, and issue #11 already proposes an
`AttestEvidenceRecorder`. This section therefore looks for what that issue does *not* cover.

### Certificate Transparency — RFC 6962

Laurie, Langley, Kasper. RFC 6962 (Experimental, 2013), obsoleted by RFC 9162 (CT v2.0).

- Targets CA misissuance by making issuance publicly observable rather than by preventing it.
  The spec is explicit about the limit: "The logs do not themselves prevent misissue, but
  they ensure that interested parties (particularly those named in certificates) can detect
  such misissuance."
- Detection is not automatic either: "The logs do not themselves detect misissued
  certificates; they rely instead on interested parties, such as domain owners, to monitor
  them."
- The log is an append-only **Merkle Hash Tree** over SHA-256, with domain-separated
  hashing — `0x00` prefix for leaves, `0x01` for internal nodes — because "this domain
  separation is required to give second preimage resistance." Tree shape "is uniquely
  determined by the number of leaves."
- An **audit path** (inclusion proof) is "the shortest list of additional nodes in the
  Merkle Tree required to compute the Merkle Tree Hash for that tree." Verification
  recomputes the root: "If the root computed from the audit path matches the true root, then
  the audit path is proof that the leaf exists in the tree."
- A **consistency proof** is the append-only guarantee, verifying "that the first m inputs
  D[0:m] are equal in both trees" — the old tree is a prefix of the new one, so nothing was
  rewritten. Both proof types are O(log n), bounded by ceil(log2(n)) + 1 nodes.
- A **Signed Certificate Timestamp** is "the log's promise to incorporate the certificate in
  the Merkle Tree within a fixed amount of time known as the Maximum Merge Delay (MMD)."
  The MMD bounds exposure: it is "the maximum period of time during which a misissued
  certificate can be used without being available for audit."
- The **Signed Tree Head** commits to a root hash, tree size, and timestamp, and must be
  available on demand no older than the MMD, re-signed with a fresh timestamp even when
  nothing new is submitted.
- Two roles do the work. **Monitors** watch whole logs — "a monitor needs to, at least,
  inspect every new entry in each log it watches." **Auditors** check partial data:
  consistency between any two STHs, and inclusion of an SCT in an STH dated after
  SCT timestamp + MMD.
- **Gossip** catches equivocation: "All clients should gossip with each other, exchanging
  STHs at least; this is all that is required to ensure that they all have a consistent
  view," and "as soon as two conflicting Signed Tree Heads for the same log are detected,
  this is cryptographic proof of that log's misbehavior." Consistency proofs mean "if a log
  attempts to show different things to different people, this can be efficiently detected by
  comparing tree roots."
- An SCT proves publication, not correctness: it "is not a guarantee that the certificate is
  not misissued."

**Primitive — transparency logs shift the goal from prevention to efficient, third-party
detection.**
**Verdict:** `should investigate`, tracked by issue #11, which this reading substantially
strengthens rather than changes. Two things in RFC 6962 go beyond that issue's scope and are
worth capturing before the adapter is built.

First, **hash chain and Merkle tree are not the same guarantee.** A hash-chained log makes
tampering detectable by replaying the chain — verification is O(n) and requires the whole
log. A Merkle tree adds O(log n) inclusion proofs and consistency proofs, which is what lets
a *third party* verify one record, or verify that the log has only ever been appended to,
without holding or trusting the whole log. Issue #11 buys detection-on-verification;
CT-style proofs would buy portable verification. That is a deliberate scope choice worth
recording as such rather than discovering later.

Second, and more important: **a tamper-evident log nobody verifies is not a control.** CT
works because monitors and auditors are a defined, staffed role with a bounded latency
(the MMD). Issue #11 delivers `attest:verify` as a command; it does not establish who runs
it, how often, or what happens when it fails. `docs/limitations.md`'s "Operational
responsibilities" list is the natural home for a verification cadence, and it currently ends
at "review data release, provider, logging, and retention practices." Without that, an
attested evidence store is strictly better than a mutable one but still only detects
tampering at the moment somebody chooses to look.
**Candidate:** `evidence-verification-cadence`

**Primitive — non-equivocation requires an external observer.** A single operator can serve
a self-consistent forked view forever; only gossip or an external anchor exposes it.
**Verdict:** `already implements` by proxy, and issue #11 should say so. Attest's optional
Bitcoin-anchored timestamping is the external anchor that plays gossip's role for a
single-operator log — it makes the operator unable to rewrite history without contradicting
a commitment they do not control. That is the strongest argument for anchoring being part of
the adapter rather than an optional extra, and it is not currently the stated rationale.

---

### SCITT — architecture

`draft-ietf-scitt-architecture-22`, IETF SCITT WG. Supply Chain Integrity, Transparency, and
Trust.

- Addresses supply-chain traceability, deliberately declining to model the semantics of the
  statements it carries, which are "opaque to Transparency Service, and MAY be encrypted."
- A **Signed Statement** is "an identifiable and non-repudiable Statement about an Artifact
  signed by an Issuer," carried as COSE_Sign1.
- A **Receipt** is "a cryptographic proof that a Signed Statement is included in the
  Verifiable Data Structure" — signed proofs of verifiable data-structure properties,
  required to support inclusion proofs and optionally consistency proofs. Critically, a
  Receipt is "universally verifiable without online access to the TS."
- A **Transparent Statement** is "a Signed Statement that is augmented with a Receipt created
  via Registration in a Transparency Service." The Receipt occupies an unprotected COSE
  header, so the object "remains a valid Signed Statement and may be registered again in a
  different Transparency Service."
- **Registration Policy** is "the pre-condition enforced by the Transparency Service before
  registering a Signed Statement." Policies and trust anchors "must themselves be registered
  on the log so they are auditable," and the service applies the most recently committed
  policy at registration time.
- Required log properties: append-only, non-equivocation, replayability. `iss` identifies the
  signer; `sub` groups statements about one artifact so relying parties can "ensure
  completeness and Non-equivocation across Statements."
- Identity is bound cryptographically at signing; authorization is separate and
  deployment-specific. "It is the role of the relying party to decide which Transparency
  Services and Issuers they choose to trust."
- The honest limit, stated plainly: "Transparency does not prevent dishonest or compromised
  Issuers, but it holds them accountable." Registration "only proves it was produced by an
  Issuer," and issuers may still make false statements or selectively withhold them.
- Explicit non-goals include revocation strategies, key discovery, statement storage,
  relying-party trust decisions, and the choice of verifiable data structure.

**Primitive — the registration policy is part of the audited record.** A log entry means
nothing unless you also know what checks were in force when it was accepted.
**Verdict:** `should adopt`. This is the **third** independent source arguing for it, after
Cedar's and OPA's decision-log revisions in section 2 and PROV's plan-and-version notion in
section 6. SCITT states it as a hard requirement: the policy must be on the log, and the
version applied is the one committed at registration time.

Verdict's `DecisionEvidence` records `targetPolicy`, `rateLimitPolicy`, and
`executionClaimPolicy` as *names* (`src/Evidence/DecisionEvidence.php`), so evidence for a
capability whose rate limit was silently raised from 5 to 500 is byte-identical to evidence
from before the change. Three separate audit and authorization literatures converging on
this makes it the best-corroborated finding in the survey.
**Candidate:** `capability-configuration-fingerprint` (from section 2; this is corroboration,
not a new candidate)

**Primitive — the receipt as a portable artifact the relying party verifies offline.**
**Verdict:** `intentionally rejects`, but the name collision deserves a note. Verdict's
`ApprovalReceipt` is not a receipt in SCITT's sense: it is a database row representing a
pending or granted human approval, validated and consumed inside the same process
(`src/Approvals/ApprovalReceipt.php`), and it never leaves as a verifiable artifact. Nothing
Verdict returns from `runBound()` lets a caller verify offline that an operation was
authorized. That is a defensible boundary for an in-process library — the caller is inside
the trust boundary already — but a reader arriving from the transparency ecosystem will
read "receipt" as SCITT's meaning. Worth a sentence in ADR 0007, which already draws the
evidence/attestation line, rather than a candidate of its own.

---

### The README no longer states that evidence is not tamper-evident — regression

Not a research finding, but it surfaced while grounding this section and it is
security-relevant.

ADR 0007 quotes the README as stating the evidence store is "an ordinary mutable audit
store... not append-only, immutable, signed, or tamper-evident," citing README:786-788
(`docs/adr/0007-evidence-layering.md:24-27`). Issue #11's problem statement quotes the same
passage as its premise.

That passage no longer exists. Commit `ea818ab` ("docs: redesign README for progressive
disclosure") reduced the README from 1,614 lines to 194 and deleted the caveat without
relocating it. Neither `docs/limitations.md` nor `docs/security-model.md` — both added in
that same commit — contains any statement that evidence is not tamper-evident.
`docs/limitations.md` covers PII inference under its fingerprint-first heading and stops
there. ADR 0007's other README citations (713-715, 779-781) are stale for the same reason.

What remains in the README is `README.md:153`, which says "context-release policies and
layered evidence help you decide what may be disclosed to a model and what audit evidence is
retained." A reader who gets only that could reasonably conclude the evidence store is
audit-grade. Under CT's framing the distinction is the whole point: an unverifiable log is a
claim by its operator, and RFC 6962 exists because that is not good enough.

The fix is small — restore the caveat to `docs/limitations.md` as its own subsection and
repoint ADR 0007 at it — but it should not wait for the ADR slate.
**Candidate:** `restore-evidence-tamper-caveat`

---

**Surveyed, no hook.** **Sigstore** and **Rekor**: keyless signing via short-lived
OIDC-bound certificates (Fulcio) with a Rekor transparency log — the ephemeral-key model
solves key management for software publishing, a problem Verdict does not have, and Rekor's
log semantics are the RFC 6962 material above. **The Update Framework (TUF)** and
**in-toto**: role separation with threshold signing, and link metadata attesting supply-chain
steps; in-toto's layout-versus-link split is structurally similar to the registration-policy
point already captured from SCITT. **Crosby and Wallach**, "Efficient Data Structures for
Tamper-Evident Logging" (USENIX Security 2009): the history tree RFC 6962's construction
derives from, and the origin of the tamper-evident-log-versus-trusted-hardware framing.
**Trillian** as a general-purpose verifiable log implementation. **RFC 9162** (CT v2.0):
algorithm agility and structural revisions over 6962; nothing that changes a Verdict
decision. Also surveyed: write-once-read-many storage and syslog signing as pre-Merkle
approaches, and the distinction between tamper-*evident* and tamper-*proof*, which ADR 0007
already draws correctly.

## 8. Digital forensics

Forensics asks a question the rest of this survey does not: months later, with the incident
over and the people gone, can anyone reconstruct what happened and defend that
reconstruction to someone hostile?

### NIST SP 800-86 — guide

Kent, Chevalier, Grance, Dang. "Guide to Integrating Forensic Techniques into Incident
Response." NIST Special Publication 800-86, 2006.

- Structures forensics as four phases — collection, examination, analysis, reporting — with
  the recurring obligation of "preserving the integrity of the information and maintaining a
  strict chain of custody for the data."
- The chain-of-custody decision is made *before* collection: analysts or management should
  decide "on the need to collect and preserve evidence in a way that supports its use in
  future legal or internal disciplinary proceedings." Where that need exists, "a clearly
  defined chain of custody should be followed to avoid allegations of mishandling or
  tampering of evidence."
- Chain of custody is enumerated concretely: "keeping a log of every person who had physical
  custody of the evidence, documenting the actions that they performed on the evidence and
  at what time, storing the evidence in a secure location when it is not being used, making
  a copy of the evidence and performing examination and analysis using only the copied
  evidence, and verifying the integrity of the original and copied evidence."
- The default is preservation: "If it is unclear whether or not evidence needs to be
  preserved, by default it generally should be preserved."
- Integrity is established by hashing — compute the digest of original and copy "then
  comparing the digests to make sure that they are the same."
- Analysis works on copies, never originals, and every step is logged with the tools used,
  because "the documentation allows other analysts to repeat the process later if needed."
- Timestamps are treated as untrustworthy by default. File times "may not always be
  accurate," with the listed causes being that "the computer's clock does not have the
  correct time... may not have been synchronized regularly with an authoritative time
  source," that time may lack the expected precision, and that "an attacker may have altered
  the recorded file times."
- Cross-source correlation is "complicated by unintentional or intentional discrepancies in
  time settings among systems," and the mitigation is organizational: "it is usually
  beneficial to analysts if an organization maintains its systems with accurate
  timestamping," via NTP.
- Retention is a policy that must be decided in advance, "supporting historical reviews of
  system and network activity, complying with requests or requirements to preserve data
  relating to ongoing litigation and investigations, and destroying data that is no longer
  needed." Organizations should "determine how many hours' or days' worth of data should be
  retained, and ensure that systems and applications have sufficient storage available."
- Collection can itself create liability: capturing content with "privacy or security
  implications" exposes it to analysts, and "long-term storage of such information might
  violate an organization's data retention policy."

**Primitive — chain of custody.** Evidence is only as good as the unbroken, documented
account of who held it and what they did to it.
**Verdict:** `already implements` in the small, and the gap is the one issue #11 addresses.
Verdict's evidence is machine-generated and never leaves the database, so most of SP 800-86's
physical apparatus is inapplicable — there is no bag, tag, or photograph. The transferable
requirement is integrity verification, which is precisely `restore-evidence-tamper-caveat`
and issue #11 from section 7.

**Primitive — record time as observed, and treat it as contestable.**
**Verdict:** `should investigate`. SP 800-86 names three causes of untrustworthy timestamps,
and two apply directly to Verdict. Every security-state timestamp — `claimedAt`,
`completedAt`, `indeterminateAt`, `recordedAt`, `expiresAt` — comes from the injected
`Clock`, bound to `SystemClock` in `src/VerdictServiceProvider.php:56`. Verdict's records
inherit the host's clock accuracy without recording that they did so, and nothing
distinguishes "this approval expired" from "this server's clock drifted." That is the same
underlying exposure as `clock-trust-assumption` from section 1 and the approval-expiry window
from section 4, seen from the investigator's side rather than the attacker's, and it belongs
in that ADR's scope as a third motivation rather than a new candidate.

The third cause — "an attacker may have altered the recorded file times" — is the tamper
question, already tracked.

**Primitive — retention decided in advance, with preservation as the default.**
**Verdict:** `already implements`, and unusually well. ADR 0009 is a retention policy
argued from first principles: execution claims are retained indefinitely because "deleting a
claim row changes the guarantee it exists to provide," and pruning by age "would silently
reopen ADR 0002's admission guarantee." It prescribes archival rather than deletion, forbids
pruning `Claimed` or `Indeterminate` rows, and refuses to ship a default window because
"short is a downstream-system property Verdict cannot know." That is SP 800-86's
preserve-by-default posture reached independently, and the rate-limit store's pruning command
is the correctly-scoped exception for state with "no ongoing meaning" (ADR 0001).

The one asymmetry: ADR 0009 governs execution claims, and no equivalent ADR governs
*evidence* retention, which `docs/limitations.md` leaves entirely to the application
("review data release, provider, logging, and retention practices"). Given that evidence is
the layer an investigator actually reads, and that SP 800-86 treats long-term retention of
sensitive content as itself a liability, the absence is defensible but undocumented as a
choice. Folding into `evidence-verification-cadence` from section 7 rather than adding a
candidate — an operator told how often to verify evidence should be told how long to keep it
in the same breath.

---

### The stale-citation problem is a chain-of-custody problem — finding

Section 7 recorded that commit `ea818ab` deleted the README's tamper-evidence caveat. Applying
SP 800-86's framing — documentation whose provenance cannot be verified is not evidence of
anything — the problem is larger than one passage.

Every ADR from 0005 through 0012 cites the README by line number. The README is now 194
lines. Counting citations in `docs/adr/`:

- `0005` cites README:188-193 and 295-296
- `0006` cites README:213-296, 254-280, 273-275 (twice), 293-296 (twice), 1218-1235, 1441-1458
- `0007` cites README:713-715 and 786-788
- `0008` cites README:713-715 (twice) and 765-767
- `0009` cites README:617 and 605-617
- `0010` cites README:678-689, 1471, and 1471-1501
- `0011` cites README:486-539, 1218-1221, 1271, and 1290 (three times)
- `0012` cites README:684 (four times) and 1218-1231, 1223-1231

That is 22 citations across 8 ADRs. All but a handful point past the end of the file, and
the survivors point into a document whose content was entirely rewritten, so none of them
resolve to what they claim. Several are load-bearing: ADR 0009's guarantee-horizon argument,
ADR 0007's evidence-layering argument, and ADR 0008's privacy-model argument each quote the
README as the normative statement the ADR is reasoning about.

ADRs are the repository's record of *why* decisions were made — the closest thing it has to
a forensic timeline. A timeline whose every citation dangles cannot be checked by the person
who most needs to check it: a future contributor deciding whether a decision still applies.
Since `ea818ab` moved content into `docs/security-model.md`, `docs/limitations.md`, and
`docs/architecture.md`, the citations should be repointed at those files by *section
heading* rather than line number, so the next reorganization does not break them again.

This is a documentation-integrity defect on the current branch, not a research finding, and
it should be fixed before the branch merges rather than queued behind the ADR slate.
**Candidate:** `repoint-adr-citations` (pairs with `restore-evidence-tamper-caveat`)

---

**Surveyed, no hook.** **Order of volatility** (RFC 3227, and SP 800-86's collection
ordering): governs what to capture first from a live host before it evaporates — Verdict's
security state is durable database rows, and its in-memory state is gone at request end by
design. **Bit-stream imaging, write blockers, and slack-space recovery**: media-level
acquisition with no software-library counterpart. **ISO/IEC 27037** (identification,
collection, acquisition, preservation) and **SWGDE** best practices: consistent with SP
800-86 on every point that transfers, and adding no distinct primitive for this analysis.
**Anti-forensics** — timestomping, log wiping, and trail obfuscation: the adversary model
that motivates tamper-evidence, already tracked by issue #11. **Forensic readiness** as a
pre-incident program: an organizational posture rather than a package feature, though it is
the frame that makes `evidence-verification-cadence` worth writing down. Also surveyed:
super-timeline construction from heterogeneous sources, and the admissibility questions
(authentication, hearsay, best evidence) that determine whether any of this survives contact
with a proceeding — out of scope for a package, but the reason chain of custody is specified
as tightly as it is.

## 9. Runtime verification

### Runtime Verification via Rational Monitor with Imperfect Information — paper

Ferrando and Malvone. ACM Trans. Softw. Eng. Methodol. 35(3), Article 74, February 2026.
`doi.org/10.1145/3735130`.

- Runtime Verification checks a running system's execution trace against a formal property,
  usually in Linear Temporal Logic. It is deliberately not exhaustive — "a violation of
  expected behaviour is only detected if it occurs in the execution trace" — which is what
  makes it lightweight enough to run in production, unlike model checking.
- Standard RV uses a three-valued verdict domain. A monitor returns ⊤ "if all continuations
  of σ satisfy φ; ⊥ if all possible continuations of σ violate φ; ? otherwise." The third
  value "is specific to RV" and exists because the system is still running: "the monitor can
  only safely conclude any of the two final verdicts if it is sure such a verdict will never
  change."
- Traditional RV assumes **perfect information** — the monitor sees everything. The paper's
  premise is that this fails for autonomous systems with faulty or unaffordable sensors.
- The core hazard of imperfect information is stated crisply: when a trace is missing an
  atomic proposition, that absence "could be incorrectly interpreted as the negation of those
  propositions." Hence, "it is crucial to differentiate between knowing that something is not
  true and recognising that something is simply unknown."
- The fix is to duplicate atomic propositions and add an explicit *undefined* value, yielding
  additional verdicts beyond ⊤/⊥/?: `uu` (no continuation satisfies or violates), `?≁⊥`
  ("unknown, but it will never be violated from the monitor's point of view"), and `?≁⊤`, its
  dual. Uncertainty is thereby graded rather than lumped.
- A **rational monitor** goes further and "can dynamically manage its visibility." Two
  classes: *active*, which reasons up front about which information it needs, and *reactive*,
  which updates its knowledge during execution.
- Visibility is treated as an economic problem. Each equivalence class of indistinguishable
  propositions carries a `cost` to make visible and a `payoff` derived from how much that
  proposition determines the formula's outcome; the monitor solves a knapsack problem to
  choose what to observe within a resource bound. The motivating example is a robot whose
  energy budget forbids polling every sensor.
- Monitors are realized as Moore machines, so the whole construction compiles to a state
  machine whose output depends only on the current state.

**Primitive — a monitor with imperfect information must distinguish "false" from "unknown,"
and must say so in its verdict domain.**
**Verdict:** `already implements`, more thoroughly than its own documentation claims, and
this paper supplies the vocabulary for saying why the design is right.

Verdict's verdict domain is not boolean. `Disposition` has five values — `Permit`, `Deny`,
`RequireConfirmation`, `RequireReview`, `Throttle` (`src/Decisions/Disposition.php`) — and
`Decision::permitsExecution()` collapses them to a boolean only at the admission point
(`src/Decisions/Decision.php`), which is the correct fail-closed reduction. `Deny` is the
paper's ⊥. `RequireConfirmation` and `RequireReview` are `?` — the monitor declining to
conclude — and they are *active* in exactly the paper's sense, because each one triggers
the acquisition of the missing information rather than guessing at it.

The same distinction appears again in the claim state machine.
`ExecutionClaimStatus::Indeterminate` exists precisely because Verdict cannot see whether a
thrown executor's side effect landed, and `VerdictManager::executeAfterRateLimit()` marks the
claim indeterminate rather than released on a throw (`src/VerdictManager.php:346-358`).
Releasing it would be the error the paper names: treating absence of confirmation as
confirmation of absence. ADR 0002 reached this conclusion from first principles; the RV
literature shows it is a general theorem about monitors, not a payments-specific judgement
call.

Worth stating in `docs/security-model.md`, which currently presents the threat model as a
list of failures Verdict makes "less likely" without naming why some outcomes are
deliberately inconclusive. Small documentation gain, no code change.

**Primitive — acquiring visibility has a cost, and which unknowns to resolve is a budgeted
choice.**
**Verdict:** `already implements` the mechanism, `should adopt` the framing. Verdict already
exposes exactly this trade twice. `ExecutionTargetPolicy` offers `refresh()` against
`acceptStaleSnapshot()` — buy fresh information with a database read, or accept a stale
snapshot as "an explicit choice" (`docs/security-model.md`). And `requiresConfirmation()`
buys the most expensive visibility available: a human.

This is the **fourth** independent source bearing on `confirmation-fatigue-guidance`, after
tacit's confirmation fatigue in section 1, the confused-deputy literature's "even
sophisticated users grow habituated to clicking OK" in section 3, and its own citation of
the same effect. The contribution here is the formal reason: the paper models the monitor's
resource bound explicitly and selects observations by payoff, where payoff is how much the
proposition actually determines the outcome. Applied to Verdict, human attention is the
bounded resource, and a capability that requires confirmation for facts the human cannot
meaningfully evaluate spends the budget for no payoff. That is a sharper piece of guidance
than "don't over-prompt," and it is the argument `docs/security-model.md`'s
`requiresConfirmation()` section should make.

**Primitive — monitorability.** Only some properties can be decided from a finite prefix of
an execution.
**Verdict:** `already implements` implicitly, and the framing is worth borrowing. Verdict's
guarantees are safety properties — nothing unauthorized is *admitted* — and a safety
violation is always witnessed by a finite prefix, which is why an in-process monitor can
enforce them at all. Every limitation in `docs/limitations.md` that Verdict declines is a
liveness or downstream property: that a refund eventually completes, that no bypassed path
exists, that a provider behaved. Those cannot be concluded from what Verdict observes, at
any point, by construction rather than by omission. Saying so would convert that document
from a list of caveats into a statement of where the boundary necessarily falls.

**Primitive — the rational monitor's knapsack over cost and payoff.**
**Verdict:** `intentionally rejects`. Verdict has no formal property to compute payoffs
against, no LTL specification of its capabilities, and no automatic way to price a database
read against a human interruption. The capability author makes these choices declaratively,
per operation, which is the same decision made by a person instead of a solver — appropriate
for a library whose safeguards are meant to be "independent choices per operation"
(`docs/security-model.md`) and legible to a reviewer. Automating it would require the
application to specify its capabilities in temporal logic, which is a far larger imposition
than Verdict makes anywhere else.

---

**Surveyed, no hook.** **LTL₃ and the Bauer–Leucker–Schallhart** three-valued semantics that
the paper builds on: the source of the ⊤/⊥/? domain, already captured above. **Monitor
synthesis from LTL to Moore machines**, and the automata constructions (`Ã` variants) the
paper re-engineers: implementation machinery for a specification language Verdict does not
have. **Decentralised and distributed RV**: monitors coordinating over partial local traces,
which is the multi-process case Verdict explicitly is not. Also surveyed: the paper's robotic
case study and its prototype LTL monitoring library, and the related work on monitorability
under silent actions and unknown event interleaving — the latter is a different uncertainty
model (ordering unknown, content known) from Verdict's (content unobservable).

### An In-Depth Study of Runtime Verification Overheads during Software Testing — paper

Guan and Legunsen. ISSTA '24, Vienna, September 2024. `doi.org/10.1145/3650212.3680400`.

- An empirical study, not a technique. The authors monitored 182,547 developer-written unit
  tests in 1,544 open-source Java projects (10,897,631 SLOC) with JavaMOP against 160 specs
  of correct JDK API usage, and profiled where the time went.
- Overhead is bimodal. Mean 23.6x (249.1 seconds); 40.9% of projects fall under the 12.48
  second absolute threshold prior work called acceptable, while the worst project pays
  5,002.9x and another pays 28.7 hours.
- The headline finding: **only 0.13% of 3,432,878,467 collected traces are unique.** A trace
  maps one-to-one to a monitor and to a (program path, spec) pair, so 99.87% of the monitors
  RV generates "can only find bugs that the other 0.13% find."
- The same section bounds how much that could ever buy: a hypothetically perfect technique
  generating only the necessary 0.13% of monitors "would still process 38.84% of
  51,203,201,000 events." The waste is in monitor creation, not in event traffic.
- Against conventional wisdom in the field, **instrumentation dominates monitoring** — 60.5%
  of RV time across all projects, and 73–77% within the first three quartiles. The authors
  note this has been invisible because "RV research ... often targets RV in deployment where
  instrumentation cost is incurred once during startup."
- 36.74% of monitoring time (excluding instrumentation) is spent in test code (21.87%) or
  third-party libraries (14.87%). The paper declines to call this waste, because excluding
  them "can lead to false positives or negatives."
- The proofs of concept — offline compile-time instrumentation (8x average reduction, but
  failed outright in 253 projects) and incremental re-instrumentation per commit (up to 4.53x
  faster than evolution-aware RV) — are AspectJ bytecode-weaving engineering.
- One sentence carries the study's scope, and it is the sentence that matters here:
  "Repeated checking in RV of deployed systems is useful to recover from violations or
  mitigate attacks."

**Primitive — redundant monitoring is waste only when the monitor's purpose is discovery.
Under enforcement, the repetition is the mechanism.**
**Verdict:** `already implements`. This entry exists chiefly to record the boundary before
someone cites the 99.87% figure at Verdict, because Verdict's design is, by that metric,
almost entirely wasteful — deliberately.

ADR 0002 re-runs the whole gate chain on every duplicate: execution-stage authorization,
confirmation consumption, and semantic rate-limit consumption all happen before the atomic
claim, and "concurrent or later duplicates are denied whether the original claim is active,
completed, or indeterminate." Under the paper's accounting, the second presentation of a
logical operation observes a trace already seen and produces a monitor that can find nothing
new. Under enforcement it is the only thing standing between a retried webhook and a second
refund. ADR 0002 also refuses the optimization outright — "no lease, timeout, automatic
retry, or cached raw result is provided" — and the only memoization anywhere in `src/` is of
monotonic schema facts, never of a decision (`Capabilities/CapabilityRegistry.php:34`,
`Capabilities/DatabaseCapabilityConfigurationStore.php:89`,
`Approvals/DatabaseApprovalStatusReader.php:21`; a table cannot un-migrate, so those answers
cannot go stale in the unsafe direction).

**Primitive — deduplication that denies is safe; deduplication that skips is not.**
**Verdict:** `already implements`, and this is the rule the previous primitive reduces to.
Verdict does fingerprint and deduplicate: the claim identity is a hash of capability name,
claim-policy name, and the application's canonical logical-operation binding. What it never
does is treat a repeat as a reason to *skip* work. The paper's own safety argument for
skipping is stated conditionally — incremental instrumentation "cannot miss new violations if
tests pass and tests are deterministic" — and neither condition is available to a monitor
whose subject is an adversary. A future contributor proposing a decision cache is proposing
the unsafe direction of a distinction this paper draws in one line; worth naming in
`docs/security-model.md` next to the gate ordering.

**Primitive — a redundancy metric presumes a deterministic subject.**
**Verdict:** `already implements`, one level away. Verdict's evaluation suite repeats each
case across trials, which the paper's metric would count as near-total waste. It is not: the
subject is a nondeterministic model, so each trial is a fresh sample rather than a repeat of
the same trace, and ADR 0021 / ADR 0022 exist to *require* enough repetition per case before
a live verdict is trusted. The paper's framing is a clean way to say why the two disciplines
point opposite directions — trace dedup is sound exactly when the coverage-adequacy gate is
unnecessary.

**Primitive — measure the plumbing separately from the check, because the plumbing usually
wins.**
**Verdict:** `already implements` in method. `docs/benchmarks.md` profiles the durable-store
paths — `DatabaseExecutionClaimStore::claim()`, `DatabaseRateLimitStore::consume()`,
`DatabaseApprovalReceiptStore::issue()` — and not the in-process policy evaluation, which is
the same allocation of attention the paper arrived at empirically. The paper's finding that
60.5% of RV time is instrumentation is the general form of that choice, and it is a reason
not to spend effort optimizing a decision engine.

The paper's own scoping also explains why its instrumentation result does not transfer:
Verdict is the deployment case, where the wiring is paid once at service-provider boot and
amortized across a long-lived worker, rather than re-paid from scratch every CI run.

**Primitive — report overhead as both an absolute and a relative figure, with outliers
separated.**
**Verdict:** `should investigate`, deliberately unfiled. Verdict has no end-to-end
guarded-versus-unguarded overhead figure; `docs/benchmarks.md` measures store-call latency
under contention and explicitly disclaims being a capacity guarantee. The paper's shape —
`t` against `t_rv`, both ratio and seconds, correlations reported with and without outliers
because a ratio against a fast baseline misleads — is the template if such a figure is ever
published. It is not proposed as work: the honest denominator for a guarded tool call is a
model round trip, which would make the resulting number a favourable comparison rather than
an engineering result, and this repository's benchmark discipline exists to avoid publishing
exactly that.

---

**Surveyed, no hook.** **Offline and incremental compile-time instrumentation**, and the
proposed public repository of pre-instrumented library jars: AspectJ bytecode-weaving
engineering with no analogue in a library that guards at a declared capability boundary.
**Evolution-aware RV** — re-monitoring only the specs a commit affects — for the same reason.
**The 36.74% spent monitoring test code and libraries**: the question does not arise for
Verdict, whose monitored surface is closed and declarative (ADR 0027) rather than derived by
matching specs against whatever bytecode happens to load; an undeclared path is simply
unguarded, which `docs/limitations.md` already owns. Also surveyed: the weak correlations
between overhead and program characteristics (Table 3), the per-quartile component
breakdowns, and the method-based analysis proposed for locating repetitive traces inside
loop-heavy programs.

## 10. Lamport

A scoped cut, chosen for bearing on questions this survey already raised rather than for
coverage.

### Buridan's Principle — paper

Leslie Lamport. Written 1984, published *Foundations of Physics* 42 (2012).

- States a general impossibility: "**Buridan's Principle.** A discrete decision based upon an
  input having a continuous range of values cannot be made within a bounded length of time."
- The parable: the ass starves because it cannot decide which of two hay piles to eat "within
  the bounded length of time before it starves." The escape is to give up one of the two
  properties — "a continuous mechanism must either forgo discreteness, permitting a
  continuous range of decisions, or must allow an unbounded length of time to make the
  decision."
- The railroad crossing makes it concrete and consequential: a driver "must make a discrete
  decision, to wait for the train or to cross the tracks, in the bounded length of time before
  the train gets there. By Buridan's Principle, this is impossible."
- In computing the same thing is called the **Arbiter Problem** — "a device that makes a
  discrete (usually binary) decision based upon a continuous input value is called an
  arbiter."
- The classic instance is interrupt handling. While the interrupt flag is being set "its state
  is a continuous function of the time at which the device began setting it," so an
  unsynchronized peripheral gives the CPU a continuous input for a binary decision it must
  make before the next instruction. "The computer is thus trying to do something that is
  impossible."
- The physical symptom is metastability: intermediate voltages that "could be interpreted as
  a 0 by some circuits and a 1 by others." The machine "stops acting like a digital device and
  starts acting like a continuous (analog) one, with unpredictable results."
- The problem went unrecognized "because engineers did not believe that their binary circuit
  elements could ever produce '1/2's'."
- The engineering resolution is the important part, and it is not a fix. The problem "is solved
  in modern computers by allowing enough time for deciding so the probability of not reaching
  a decision soon enough is much smaller than the probability of other types of failure" —
  deciding after the third succeeding instruction rather than the current one. With good
  design "the probability of not having reached a decision by time t is an exponentially
  decreasing function of t."
- Crucially, "the problem is not one of making the 'right' decision... the problem is simply
  making a decision."

**Primitive — a boundary decision on a continuous input cannot be made reliably in bounded
time; buy margin instead.**
**Verdict:** `should adopt`, and this converts an existing candidate from a caveat into
actionable guidance.

`ApprovalReceipt::isExpiredAt()` returns `$time >= $this->expiresAt`
(`src/Approvals/ApprovalReceipt.php:28`) — a discrete decision (expired or not) over a
continuous input (time), evaluated at a moment Verdict does not control, against a clock
whose accuracy Verdict does not verify (`src/VerdictServiceProvider.php:56`). Section 4
established via Kleppmann that the window between this check and the executor's side effect
cannot be closed by rearrangement. Buridan explains *why* that is not a defect to be fixed:
it is the arbiter problem, and it has no bounded-time solution.

What it does supply is the mitigation, and it is one Verdict can state as guidance today at
no code cost. Lamport's answer to the arbiter is margin — make the decision far from the
boundary, so the probability of landing in the ambiguous region is dominated by other
failure modes. Applied to approvals: **an approval expiry window should be chosen to be much
longer than the worst-case latency between validation and execution.** A capability with a
30-second expiry in a system where approval validation, rate limiting, claim admission, and
a slow executor can span seconds is deciding near the boundary on every call. A window of
minutes decides far from it. `docs/security-model.md` currently tells applications the
binding facts matter and that approval "is consumed before execution," and says nothing about
how to size the window. That is the one genuinely actionable thing to say about it.
**Candidate:** `clock-trust-assumption` (from section 1; this supplies its recommendation
rather than adding a candidate)

---

### Time, Clocks, and the Ordering of Events in a Distributed System — paper

Leslie Lamport. CACM 21(7), 1978. Dijkstra Prize; ACM SIGOPS Hall of Fame.

From Lamport's own annotation on his publications page, plus the paper's standard content:

- The framing came from special relativity: there is no invariant total order on events, only
  a partial order in which one event precedes another "iff e1 can causally affect e2."
- The insight he singles out is that timestamps can supply a total order *consistent with*
  causality — "This realization may have been brilliant. Having realized it, everything else
  was trivial."
- He notes the prior algorithm by Johnson and Thomas "allowed anomalies violating causality,"
  which his correction removes.
- He complains that readers miss the paper's actual subject: implementing an arbitrary
  distributed state machine, since a distributed system "can be described as a particular
  sequential state machine." Mutual exclusion was chosen only as the simplest illustration.
- The physical-clock synchronization theorem was "something of an afterthought," and its
  unexpectedly hard proof foreshadowed later clock-synchronization work.

**Primitive — causality is a partial order; timestamps are a device for extending it, not a
substitute for it.**
**Verdict:** `should investigate`, and this is the cleanest statement of the section 6
finding. Verdict's evidence records `recorded_at` from the injected clock and indexes on
`['record_type', 'correlation_id', 'recorded_at']`. Within a correlation, that ordering is
meaningful. *Across* the two correlation namespaces — provenance keyed by Laravel AI's
invocation id, decisions keyed by the envelope UUID (`src/Evidence/DatabaseEvidenceRecorder.php:29`,
`:131`) — there is nothing but timestamps, and Lamport's point is precisely that timestamps
without a causal edge cannot establish that one event could have affected another. Two records
a millisecond apart may be causally linked or entirely unrelated, and the log cannot say
which. Threading the invocation id onto the envelope is exactly the missing happens-before
edge. Reinforces `provenance-decision-correlation`.

---

### Specification method — annotations

Lamport's annotations on SIFT (1978) and *On-the-fly Garbage Collection* (1978).

- On SIFT he calls it "a very early example of the basic specification and verification method
  I still advocate": write the spec as a state-transition system, then show each lower-level
  step either implements a higher-level step or is "a 'stuttering' step" leaving the
  higher-level state unchanged.
- On the garbage-collection paper he states the lesson that shaped everything after: "behavioral
  proofs are unreliable and one should always use state-based reasoning for concurrent
  algorithms."

**Primitive — express correctness as a predicate over states, not as an argument about the
order in which code runs.**
**Verdict:** `should adopt`, and it is the strongest available argument for two candidates
this survey has already raised on independent grounds.

Both of them are, right now, behavioral arguments. `reauthorize-after-refresh` (section 2)
holds because `VerdictManager::runBound()` happens to refresh the target at
`src/VerdictManager.php:182` before re-authorizing at `:192`; reorder those statements and
nothing fails. `single-contended-row-invariant` (section 5) holds because
`DatabaseRateLimitStore::consumeLocked()` happens to lock one row before reading `attempts`,
and because a unique index happens to exist in a migration stub; change either and the write
skew returns silently. In both cases the correctness argument lives in the sequence of
statements, which is exactly the reasoning Lamport says is unreliable.

The state-based restatements are short and testable. For the first: *no execution-stage
decision may be derived from a target snapshot older than the refresh for that envelope.*
For the second: *every rate-limit and execution-claim constraint corresponds to exactly one
database row, protected by a unique index, and admission requires holding a lock on it.*
Written that way, they are properties a test or a reviewer can check against, rather than an
ordering a refactor can quietly break. Issue #19 already consolidates "the accepted gate
ordering for readers" (ADR 0004) and is the natural place for the first.

---

**Surveyed, no hook.** **The Byzantine Generals Problem** (1982) and **Reaching Agreement in
the Presence of Faults** (1980), read via Lamport's annotations rather than in full: 3n+1
processors to tolerate n faults, or 2n+1 with digital signatures. Verdict has a single
trusted process and no replicas to disagree, so there is no agreement problem to solve.
Lamport's observation that the signatures there are "a metaphor," needing security only
against random failure rather than an adversary, is a useful caution against reading
cryptographic machinery into problems that do not have adversaries — but it changes nothing
here. **Sequential consistency** (1979): Lamport credits the paper's value to its "simple,
precise definition of sequential consistency" as a correctness condition for
multiprocessors. Verdict's ordering guarantees are enforced by database transactions and row
locks, not by a memory model, and PHP's request-scoped shared-nothing execution means there
is no concurrent-memory question inside the process at all. **TLA+**, **The Temporal Logic of
Actions**, and *Specifying Systems*: the tooling that operationalizes the state-based
reasoning above — not proposed for Verdict, which has neither the concurrency surface nor the
verification budget to justify a formal specification, though the *discipline* of writing
invariants as state predicates transfers for free and is the recommendation recorded above.
Also surveyed: Paxos and *The Part-Time Parliament* (consensus, already dismissed in section
4), and the bakery algorithm.

## 11. Zero Trust and identity propagation

The emphasis here is identity propagation: when an AI agent takes an action, whose identity
is it acting under, and can the system tell afterwards?

### NIST SP 800-207 — Zero Trust Architecture

Rose, Borchert, Mitchell, Connelly. NIST SP 800-207, August 2020.

- Zero trust moves defenses "from static, network-based perimeters to focus on users, assets,
  and resources," assuming "no implicit trust granted to assets or user accounts based solely
  on their physical or network location." Ownership earns nothing either.
- It "focuses on protecting resources (assets, services, workflows, network accounts, etc.),
  not network segments," because network position "is no longer seen as the prime component
  to the security posture of the resource."
- Tenet 3: "Access to individual enterprise resources is granted on a per-session basis...
  Access should also be granted with the least privileges needed to complete the task.
  However, authentication and authorization to one resource will not automatically grant
  access to a different resource."
- Tenet 4: "Access to resources is determined by dynamic policy—including the observable state
  of client identity, application/service, and the requesting asset—and may include other
  behavioral and environmental attributes." Client identity "can include the user account (or
  service identity) and any associated attributes... or artifacts to authenticate automated
  tasks."
- Tenet 6 is the operative one here: "All resource authentication and authorization are
  dynamic and strictly enforced before access is allowed. This is a constant cycle of
  obtaining access, scanning and assessing threats, adapting, and continually reevaluating
  trust." Reauthorization triggers are enumerated as "time-based, **new resource requested,
  resource modification**, anomalous subject activity detected."
- Tenet 5: "No asset is inherently trusted," and posture is evaluated at request time.
- Tenet 7: collect as much state as possible and feed it back into policy.
- The tenets are explicitly aspirational: "not all tenets may be fully implemented in their
  purest form for a given strategy."

**Primitive — reauthorize on resource modification, not once per session.**
**Verdict:** `already implements`, and this is the **third** independent corroboration of
`reauthorize-after-refresh`, after Zanzibar's new enemy problem (section 2) and Lamport's
argument for stating it as a state invariant (section 10). NIST lists "resource
modification" as a named reauthorization trigger; Verdict's `runBound()` refreshes the target
and re-runs `$this->authorizer->decide(...)` against the refreshed value
(`src/VerdictManager.php:182`, `:192`). Verdict satisfies tenet 6 on its protected path and
does not say so anywhere.

**Primitive — per-request authorization scoped to one resource, granting nothing else.**
**Verdict:** `already implements`. Tenet 3's "authentication and authorization to one
resource will not automatically grant access to a different resource" is exactly the argument
`docs/security-model.md` already makes about approvals: approval "is for that binding—not for
a broad conversational intent." A conversation is a session; Verdict deliberately refuses to
treat it as an authorization scope. Useful external backing for a design choice the docs
currently assert without support.

---

### RFC 8693 — OAuth 2.0 Token Exchange

Jones, Nadalin, Campbell (Ed.), Bradley, Mortimore. IETF, January 2020. Standards Track.

- Defines a token-exchange grant so an authorization server can act as a Security Token
  Service, for the case where service A must call service C on behalf of user B.
- **Impersonation** collapses identities: when A impersonates B, A "is indistinguishable from
  B in that context," and "for all intents and purposes, when A is impersonating B, A is B
  within the context of the rights authorized by the token." Downstream sees only B.
- **Delegation** preserves both: "principal A still has its own identity separate from B," and
  "any actions taken are being taken by A representing B." The RFC's summary — "in a sense, A
  is an agent for B."
- The request distinguishes them structurally. `subject_token` (required) "represents the
  identity of the party on behalf of whom the request is being made"; `actor_token`
  (optional) "represents the identity of the acting party." With only a subject token,
  "delegation is impossible," so the request is inherently impersonation.
- The **`act` claim** exists to "express that delegation has occurred and identify the acting
  party to whom authority has been delegated." It carries identity claims only — `exp`, `nbf`,
  and `aud` "are not meaningful when used within an `act` claim."
- `act` claims **nest into a chain**: "the outermost `act` claim represents the current actor
  while nested `act` claims represent prior actors," recording delegation history back to the
  original subject.
- The access-control rule is strict: consumers "MUST only consider the token's top-level
  claims and the party identified as the current actor." Prior actors are informational and
  must not affect authorization.
- **`may_act`** is the pre-authorization counterpart — it "makes a statement that one party is
  authorized to become the actor and act on behalf of another party," consulted by the STS
  before issuing.
- Security considerations are blunt: "both delegation and impersonation introduce unique
  security issues," and "any time one principal is delegated the rights of another principal,
  the potential for abuse is a concern." The recommended mitigations are narrow `scope` and
  short lifetimes, since scope "restricts the contexts in which the delegated rights can be
  exercised." Privacy guidance suggests minimizing token data and using pseudonymous
  identifiers.

**Primitive — delegation is impersonation plus a preserved, auditable acting identity.**
**Verdict:** `should investigate`, and this is the **fifth** appearance of the delegation
theme — after `authgate-kernel`'s signed chains (section 1), tacit's sub-agent capability
subsets (section 1), ocap attenuation and caretakers (section 3), and PROV's `actedOnBehalfOf`
(section 6). RFC 8693 is the most directly transferable of the five, because it is a deployed
standard whose data model is two fields and a nesting rule rather than a new architecture.

`ActionContext` carries a single `mixed $actor` plus freeform `metadata`
(`src/Actions/ActionContext.php`), and `LaravelPolicyAuthorizer` evaluates
`forUser($envelope->context->actor)`. In RFC 8693's terms Verdict is **impersonation-only by
construction**: the application must pick one identity, and whichever it picks, the other is
lost. Pass the human user and the record cannot show an agent was involved; pass a service
identity and the policy no longer reflects the human's authority. There is no way to say
"agent A acting for user B," which is precisely the situation Verdict exists to govern.

The RFC's own mitigation for delegation risk — narrow scope and short lifetime — is
interesting here because Verdict already has both, in `Capability` scoping and approval
expiry. What it lacks is the acting identity that would make those mitigations attributable.
Worth noting that the RFC's "consumers MUST only consider... the current actor" rule means a
delegation chain is for audit, not authorization — which keeps the scope of any Verdict
version of this small.

---

### DecisionEvidence records no actor — finding

Following the identity thread into the evidence schema produced the sharpest gap in this
section.

`DecisionEvidence` has 25 fields (`src/Evidence/DecisionEvidence.php:12-38`). They cover the
envelope id, capability, stage, disposition, reason, argument fingerprint, idempotency key,
approval receipt fingerprint and phase and outcome, target policy and strategy and both target
identity fingerprints and whether they matched, rate-limit key fingerprint and policy and
limit and remaining and reset time, execution claim fingerprint and binding fingerprint and
policy and status and attempt, and the recorded timestamp.

None of them identifies the actor.

Verdict's central claim is "Models propose. Applications authorize," and the authorization it
performs is `forUser($actor)`. The evidence records what was decided, about which capability,
against which target, under which policies — and omits the subject the decision was made
about. An investigator reading `verdict_evidence` can establish that a refund was permitted
against a target whose identity fingerprints matched, and cannot establish who did it. Under
NIST tenet 4, client identity is the primary input to the policy decision; under SP 800-86
(section 8), the point of the record is later reconstruction.

The omission is not a documented decision. ADR 0008, the evidence privacy model, contains no
mention of actor, subject, or identity at all. And the privacy rationale that would justify
it argues the other way: every other potentially sensitive value in this schema is recorded as
a SHA-256 fingerprint rather than dropped — arguments, targets, rate-limit keys, claim
bindings. An `actorFingerprint` would be consistent with ADR 0008's fingerprint-first model,
would support correlation across records without storing an email address, and matches
RFC 8693's own privacy guidance about pseudonymous identifiers. The `rateLimitKeyFingerprint`
field partially covers this today, but only when a rate limit is configured, and only as an
opaque composite.

This is a small schema addition with a large investigative payoff, and it is the natural place
to also carry an optional acting identity if the delegation question above is ever taken up.
**Candidate:** `actor-identity-in-evidence`

**Closed 2026-08-11.** `DecisionEvidence` now carries `actorFingerprint` and
`subjectFingerprint` (`src/Evidence/DecisionEvidence.php`), derived through the same SHA-256
fingerprint path as every other identity value in the schema, persisted by both the database
and attest recorders, and indexed on `actor_fingerprint`. The finding above is retained as
written because the reasoning is what the log exists to keep; the gap it names is no longer
open.

---

### SPIFFE — specification

`spiffe.io`, SPIFFE concepts. SPIRE is the reference implementation.

- Gives workloads cryptographically verifiable identity "without shipping bootstrap secrets
  alongside the application," solving the secret-zero problem for service-to-service auth.
- A **SPIFFE ID** "is a string that uniquely and specifically identifies a workload," shaped
  as `spiffe://<trust domain>/<workload identifier>`.
- A **trust domain** "corresponds to the trust root of a system," and should split along
  physical or practice boundaries — staging must not share a trust domain with production.
- An **SVID** "is the document with which a workload proves its identity to a resource or
  caller," valid "if it has been signed by an authority within the SPIFFE ID's trust domain."
  X.509-SVIDs are preferred; JWT-SVIDs are "susceptible to replay attacks" but necessary when
  an L7 proxy terminates the connection.
- The Workload API "does not require that a calling workload have any knowledge of its own
  identity, or possess any authentication token when calling the API" — identity is derived
  from what the platform can observe about the caller, not from what the caller presents.
- All keys and certificates are short-lived and automatically rotated, narrowing the exposure
  window from any leak.
- SPIFFE assumes workload isolation is provided elsewhere and declares it out of scope.

**Primitive — identity derived from observable properties rather than presented credentials.**
**Verdict:** `intentionally rejects`, and the reason is worth writing down once because the
question will recur. Verdict is an in-process library. There is no network hop between the
proposer and the enforcer, no workload to attest, and no credential to rotate — the actor
arrives as a PHP object in `ActionContext`, and the trust boundary Verdict polices is between
a *model's proposal* and an *application's execution*, not between two services. Adopting
workload identity would mean Verdict authenticating a caller that is already inside its own
process, which is the confused-deputy mitigation applied to the wrong boundary.

The transferable observation is narrower and already recorded: SPIFFE separates *workload*
identity from *user* identity as a matter of course, which is the same separation RFC 8693
formalizes as subject and actor, and which Verdict's single `$actor` field cannot express.

---

**Surveyed, no hook.** **SPIRE**: node attestation and workload attestation, the registration
model, and nested SPIRE servers — implementation machinery for the identity model rejected
above. **BeyondCorp** (Google's papers, 2014 onward): device inventory, tiered trust, and the
access proxy that moved authorization from the VPN perimeter to a per-request gateway. The
access-proxy pattern is the PDP/PEP split already covered by OPA in section 2, and the device
trust tier has no counterpart in a library that never sees a device. **NIST SP 800-207A**
(zero trust for cloud-native application access) and **CISA's Zero Trust Maturity Model**:
deployment guidance and maturity scoring for enterprises, not primitives. **mTLS** and service
meshes as the transport for workload identity. **OAuth 2.0 scopes and the `azp`/`client_id`
claims**, and **OpenID Connect** ID tokens: the surrounding ecosystem RFC 8693 extends. Also
surveyed: continuous access evaluation as an alternative to short token lifetimes, and the
"never trust, always verify" formulation, which the NIST tenets state more precisely and less
sloganishly.

## 12. Agent governance — the three closing papers

Three 2026 papers on securing autonomous agents specifically. They are the most directly
comparable prior art to Verdict in this entire log, and they are useful mainly as an outside
check on whether Verdict's design choices are the ones this field converges on.

### Ramachandran & Mishra — Identity-Aware Governance for Autonomous AI Agents

SSRN 6439998, March 2026. Framework paper, developed alongside a NIST NCCoE comment
submission.

- Frames the core defect as **ambient authority inheritance**: "the agent operates with
  whatever credentials exist in the runtime environment, regardless of who triggered the action
  or what they are authorized to do," so "a junior engineer's prompt and a principal
  architect's prompt execute with identical permissions."
- The paper's central distinction is **governance by trust versus governance by enforcement**.
  Governance by trust "places policy instructions in prompts or configuration files that the
  model reads and chooses to comply with," and "provides no security guarantees." Governance by
  enforcement "places policy evaluation at an infrastructure layer that the agent cannot access
  or modify," where "authorization decisions are made by deterministic systems outside the
  model's context window." Only the second "provides the assurance level required."
- Proposes a four-layer identity model: agent type, agent instance (SPIFFE-aligned), **delegated
  human identity** — "whose authority the agent operates under... the critical missing layer in
  all current agent frameworks" — and session/task identity.
- Requires **identity-inherited permissions**: agents "inherit the scoped permissions of the
  triggering user, not ambient credentials. This limits blast radius of any compromise to what
  the triggering user could have done directly."
- Names the **oracle problem**: "the agent is simultaneously actor and witness to its own
  actions. An agent can report success while having silently failed, hallucinated the action, or
  taken a different action than intended." Current proposals to log the reasoning chain "rely on
  the agent itself producing this log, with no independent verification."
- Treats **hallucination as a first-class security threat**, distinct from adversarial attack:
  agents hallucinate tool parameters, hallucinate authorization ("reasoning that they have
  permission when they do not"), and emit chain-of-thought that is "post-hoc rationalizations
  rather than accurate reflections." Audit systems should "treat reasoning chains as
  supplementary evidence rather than authoritative records."
- Names **composition attacks within authorized scope**: "each individual step passes per-call
  authorization checks, but the sequence collectively achieves an unauthorized outcome." An
  authorized file read plus an authorized network call is exfiltration. Proposes sequence-aware
  evaluation, then concedes it "cannot anticipate all novel compositions."
- Section 7.2, "The Model as Adversary": "a security framework that treats the LLM as a
  compliant, predictable component will systematically underestimate risk."
- Their top recommendation to the standards community is to **standardize "on behalf of"
  delegation**, "the single highest-impact deliverable — every other control depends on reliably
  answering 'whose authority is this agent operating under?'"

**Primitive — governance by enforcement, outside the model's context window.**
**Verdict:** `already implements`, and this paper supplies the cleanest external vocabulary yet
for what Verdict's tagline compresses into four words. "Models propose. Applications authorize"
*is* the trust/enforcement distinction. `VerdictManager::runBound()` evaluates policy in PHP,
against application-registered `Capability` and `ExecutionTargetPolicy` objects, in a code path
the model cannot reach or influence; the model's only input is the argument payload inside
`ActionEnvelope`. Nothing in the decision path reads model output as instruction. Worth citing
this framing in the docs, because "governance by trust vs governance by enforcement" explains
Verdict's value proposition to a security reviewer faster than any of Verdict's own prose does.

**Primitive — identity-inherited permissions bounded by the triggering user.**
**Verdict:** `already implements`. `LaravelPolicyAuthorizer` evaluates
`Gate::forUser($envelope->context->actor)`, so a capability resolves against the human's own
Laravel policy and the agent gains nothing the actor did not already have. Blast radius is
bounded by the actor exactly as the paper asks. This is a stronger property than Verdict
currently claims for itself.

**Primitive — the oracle problem: the agent is actor and witness.**
**Verdict:** `already implements`, structurally and probably without having framed it this way.
Two mechanisms matter. First, `DecisionEvidence` is written by `VerdictManager`, not by the
executor or the model, and the executor receives an `AuthorizedAction` carrying only envelope,
capability, and target — it has no handle on the evidence recorder or the claim. Second,
completion is inferred from control flow rather than self-report: `$output = $executor();`
(`src/VerdictManager.php:344`), and a throw marks the claim indeterminate (`:346-358`) while a
normal return completes it. A model that claims "I refunded the order" cannot cause a claim to
be marked complete; only actually returning from the executor does that. Verdict's record is
therefore a witness account, not a defendant's statement — which is the property the paper says
is missing everywhere. This deserves a documented sentence.

The residual gap is the paper's stronger version — *independent action verification*, querying
the target system to confirm the effect. Verdict deliberately does not do this
(`ExecutionResult::executed($evaluation, $output)` passes the executor's return through
untouched), and shouldn't: verifying that a refund actually landed is domain logic. Worth being
explicit that the boundary is "Verdict witnesses admission and completion, not effect."

**Primitive — composition attacks: authorized steps summing to an unauthorized outcome.**
**Verdict:** `should investigate`, with the caveat that the paper itself concedes no complete
defense exists. Verdict authorizes strictly per-call: each `runBound()` evaluates one
capability against one target, and no state carries across calls except through the stores.
The one primitive that spans calls is the semantic rate limit, and it is genuinely
sequence-aware in a limited sense — `RateLimitPolicy::fixedWindow()` takes an
application-supplied `$keyUsing` resolver (`src/RateLimits/RateLimitPolicy.php:44-52`), so an
application can deliberately key one bucket across several capabilities and give a whole class
of operations a shared budget. That converts "ten small refunds" from ten independently
authorized actions into one exhausted budget.

This is a real partial answer to composition attacks that Verdict's documentation never frames
as one — it is presented purely as rate limiting. A worked example of a cross-capability shared
bucket, explained as bounding a sequence rather than a frequency, would be a documentation
change with security value and no code change.
**Candidate:** `shared-bucket-composition-bound`

**Primitive — the delegated human identity layer.**
**Verdict:** `should investigate` — the **sixth** and final appearance of the delegation theme,
and the paper calls it "the single highest-impact deliverable" in the field. Nothing new to add
beyond section 11's `subagent-delegation-question` and `actor-identity-in-evidence`, except
that the convergence is now unanimous across every agent-security source surveyed.

---

### Llambí-Morillas & Fernández-Fernández — Toward cryptographically verifiable authorization for autonomous AI agents

arXiv:2607.21325v1 [cs.CR], July 2026. Formal model plus a Groth16 zk-SNARK proof of concept.

- Argues authorization for agents should be studied as a cryptographically verifiable relation
  binding "an agent principal, a concrete authorization request, an execution context, and the
  satisfaction of an applicable policy."
- Opens with the observation that authentication does not imply authorization, and neither does
  delegation: `Delegate(U, Ai, κ) ⇏ AuthZ(qi)`. "Delegation may establish authority over a broad
  class of operations, while the permissibility of a concrete request may still depend on the
  target resource, the execution context, the applicable policy version, and temporal validity."
- The paper's central contribution is a **three-way structural separation**, stated as
  `Identity Binding ≢ Authorization Request Binding ≢ Runtime Execution Binding`. "A system may
  correctly authenticate an agent yet fail to bind authorization evidence to a specific request.
  It may likewise bind evidence to a request without guaranteeing that the same request is
  executed at runtime. These are structurally distinct security properties requiring separate
  mechanisms."
- **Runtime execution binding is the one they cannot solve.** `VerifyAuth(pp, xq, π) = 1 ⇏
  qexec = qauth`. The gap "is structural rather than incidental. A ZK circuit is evaluated at
  proof generation time over a committed representation of the intended request; it has no
  access to the runtime state at execution time and cannot constrain what the agent subsequently
  does with the authorization it receives."
- Their TOCTOU statement is explicit: "If verification occurs at time tv and execution at
  te > tv, the relevant context may change: `ctv ≠ cte`," therefore
  `Authorized(q, ctv) = 1 ⇏ Authorized(q, cte) = 1`.
- Their proposed fix requires "a trust anchor that operates at execution time rather than at
  proof generation time... remote attestation, a trusted execution environment, or verifiable
  execution receipts." Table 2 lists runtime execution binding as **Open**, mechanism: "No
  trusted execution evidence."
- Replay resistance is a nonce plus gateway state, and they note honestly that "circuit
  verification is stateless, whereas replay resistance requires" external state.
- On authorization frequency: "a low-frequency model, in which a proof is generated once per
  task plan and verified once at the gateway, presents a fundamentally different latency budget
  from a high-frequency model in which each tool invocation triggers an independent
  authorization cycle," and reducing frequency "increases the importance of binding the
  authorized plan to subsequent execution."
- Multi-hop delegation is left open too: a proof that agent Ak satisfies a local policy "does
  not by itself establish that Ak was authorized to act under the scope delegated by Ai, or that
  scope has not been expanded across hops."
- They warn that a valid proof of the wrong relation is still wrong: if the circuit "does not
  faithfully encode the intended organizational policy... then a valid proof may correspond to
  an incorrectly authorized decision."

**Primitive — runtime execution binding, distinct from request binding.**
**Verdict:** `already implements`, and this is the most striking single finding in the log. The
property this paper formalizes as its central open problem is the property
`ExecutionTargetPolicy` exists to provide. `runBound()` refreshes the target immediately before
execution and re-authorizes against the refreshed value (`src/VerdictManager.php:182`, `:192`),
and the execution claim's binding fingerprint ties admission to that specific resolved target.
Verdict closes `Authorized(q, ctv) = 1 ⇏ Authorized(q, cte) = 1` by making tv and te adjacent
and by refusing to execute if the target moved in between.

Verdict achieves this not cryptographically but architecturally — it is *in the same process
as* the executor, so it has the execution-time trust anchor a gateway does not. That is the
whole reason an in-process library can offer something a ZK gateway cannot, and it is a
sharper argument for Verdict's deployment model than "it's convenient." It is also the fourth
corroboration of `reauthorize-after-refresh`, and the strongest, because here the absence of
the mechanism is stated as an unsolved research problem.

**Primitive — the three-layer binding separation as a design vocabulary.**
**Verdict:** `should adopt` as documentation. The triad maps onto Verdict almost field by field:
identity binding is `ActionContext::$actor` and the Laravel gate; authorization-request binding
is the argument fingerprint plus idempotency key in `DecisionEvidence`; runtime execution
binding is the target policy plus the claim binding fingerprint. Laying the three out against
Verdict's components would let a reader see which mechanism serves which property, and would
make the actor gap from section 11 visible as a hole in the first column rather than as a
missing field in a list of 25.
**Candidate:** `binding-layers-documentation`

**Primitive — scope attenuation must be proved across delegation hops.**
**Verdict:** `should investigate`. Same theme, and it adds one specific requirement the other
five sources left implicit: it is not enough to record that delegation happened, the system must
establish "that scope has not been expanded across hops." That is the ocap attenuation
requirement from section 3 restated as a verification obligation. Folded into
`subagent-delegation-question`.

**Primitive — a valid proof of an unfaithful policy is still an unsafe decision.**
**Verdict:** `already implements` at the level Verdict operates. Their concern — the encoded
relation may not match the intended policy, and nothing detects the divergence — is the reason
Verdict delegates to Laravel gates rather than defining a policy language: the application's
existing, already-tested authorization logic *is* the intended policy, so there is no encoding
step to get wrong. Worth noting as a deliberate advantage of not inventing a policy DSL (a
choice section 2 recorded against Cedar and Rego). It also reinforces
`capability-configuration-fingerprint`: what Verdict *cannot* currently detect is the policy
changing between two recorded decisions.

---

### Li et al. — Agent-BOM: Toward Security-Auditable LLM Agents

arXiv:2605.06812v1 [cs.AI], May 2026. Huazhong UST, NTU Singapore, Fudan, and others.

- Motivating problem is a **semantic gap**: "the same physical action may have different
  security meanings under different semantic paths." A file deletion "may be an authorized user
  request, or it may be an unauthorized action induced by poisoned retrieved content, malicious
  memory residue, or deceptive inter-agent messages."
- "System logs and API traces can record what happened, but they rarely explain how the goal was
  formed, how the context was contaminated, what reasoning supported the action, or why the
  decision was made." SBOMs and runtime logs "provide only fragmented evidence."
- Agent-BOM is a hierarchical attributed directed graph `BS = (A, V, E, α)` splitting a **static
  capability layer** (models, tools, prompts, long-term memory) from a **runtime semantic layer**
  (goals, contexts, reasoning, decisions, actions), joined by cross-layer binding edges that
  record "when and why a runtime state invokes a static capability object."
- Four edge families: structural dependency, runtime evolution, cross-layer binding, and
  cross-agent propagation.
- The **attribute schema** is what makes it more than a provenance graph: `α` attaches security
  metadata — `Source`, `Integrity_status`, `Basis`, `Intent`, `Environment_change`,
  `Authentication_status`, `Permission_scope`, `Confirmation_status` — to nodes and edges,
  because "graph topology can recover factual paths, but topology alone lacks normative security
  context."
- Auditing is a four-stage query: risk-entry localization, backward tracing to root cause,
  forward tracing to assess propagation, and rule-based adjudication. Rules are instantiated
  against the OWASP Agentic Top 10.
- Rule 9 (ASI09, Human-Agent Trust Exploitation) is the one closest to Verdict: entry is an
  external or observation node; adjudication requires that the entry "carries unverified or
  fabricated semantics," that backward tracing shows it "comes from a compromised external
  system," and that forward tracing shows "the agent bypasses `Confirmation_status` mechanisms
  and uses the exploited trust to execute high-risk actions."
- Rule 6 (memory poisoning) traces a `Source` attribute backward to the origin of fabricated
  memory, then forward to any downstream node that reused it — cross-session contamination is
  only visible because the trace crosses sessions.
- Deployed as a plugin, reconstructing attack chains from live executions.

**Primitive — provenance as a traversable graph rather than a flat log.**
**Verdict:** `intentionally rejects` at this scale, with one narrower piece worth taking.
`ProvenanceEntry` (`src/Evidence/ProvenanceEntry.php:16-25`) records `correlationId`, `source`,
`trust`, `dataClass`, `channel`, `contentFingerprint`, and an optional component label and
fingerprint. Its security attributes are a close match for Agent-BOM's `Source` and
`Integrity_status`: `Trust`, `DataClass`, and `ContextChannel` are exactly the "normative
security context" the paper says topology alone lacks, and Verdict attaches them by
construction rather than by instrumentation. Modelling agent goals, reasoning trajectories, and
prompt templates as graph nodes is out of scope for a library that never sees the model's
reasoning.

What Verdict lacks is **edges**. Entries sharing a `correlationId` form a set, not a chain —
`grep` for derivation or parent fields in `src/` returns nothing. Backward tracing in
Agent-BOM's sense ("this tool result was derived from that retrieved document") cannot be
performed against `verdict_provenance` because the derivation was never recorded. This is the
**second** corroboration of the gap section 6 found in W3C PROV's `wasDerivedFrom`, and the two
sources reach it from opposite directions: PROV from a general model of causality, Agent-BOM
from the specific need to trace a poisoned document to the action it caused. Two independent
arrivals at the same missing field is the strongest signal in this log for a schema change.
**Candidate:** `provenance-derivation-edges`

**Primitive — a confirmation-bypass audit rule (ASI09).**
**Verdict:** `already implements` the enforcement, and this is a useful confirmation of what
the evidence schema gets right. `DecisionEvidence` records `approvalReceiptFingerprint`,
`approvalPhase`, and `approvalOutcome`, so "did this action carry a valid confirmation" is
directly answerable — Agent-BOM's `Confirmation_status` attribute, already present. And because
Verdict enforces rather than audits, a bypassed confirmation does not produce a detectable
audit path; it produces a denial. The paper is describing detection for systems that cannot
prevent. Reinforces the enforcement-over-detection framing from the first paper in this section.

---

**Surveyed, no hook.** **OWASP Agentic Top 10 / OWASP Top 10 for LLM Applications** — the risk
taxonomy Agent-BOM's rules instantiate; useful as a checklist for a future threat-model doc but
not a primitive. **Greshake et al., indirect prompt injection** (arXiv:2302.12173) and **Zou et
al., universal adversarial suffixes** (arXiv:2307.15043) — model-layer attacks; Verdict's
position is that these are the reason enforcement lives outside the model, already covered.
**Hubinger et al., sleeper agents** (arXiv:2401.05566) — training-time backdoors as a supply
chain risk at the model layer; no library-side primitive. **ClawHavoc / CVE-2026-25253** and
Cisco's finding that 26% of 31,000 analyzed agent skills contained vulnerabilities — motivating
evidence for tool supply-chain integrity, which is the application's dependency problem, not
Verdict's. **NIST NCCoE, "Accelerating the Adoption of Software and AI Agent Identity and
Authorization"** and **NIST's RFI 91 FR 698** — active standards processes worth tracking for
whatever "on behalf of" pattern emerges, but currently draft concept papers with nothing to
implement against. **OpenID Foundation, "Identity management for agentic AI"** (2025 whitepaper)
— same territory as RFC 8693 in section 11. **NGAC** (NIST next-generation access control) —
attribute-based access control, same family as the policy engines in section 2. **Groth16 and
zk-SNARK constructions**, Poseidon hashing, trusted-setup ceremonies — the cryptographic
machinery of the CVA prototype, irrelevant to an in-process library that does not need to
convince a remote verifier of anything. **LangSmith, Prov-Agent, Agent-Sentry** — agent
observability and execution-provenance tooling; adjacent to Verdict's ledger but positioned as
external monitors rather than enforcement points.

## 13. Prompt injection prevention guidance — the practitioner corpus

Seven published prevention guides, surveyed together because the finding is their
convergence. One is community-maintained; six are vendor content that terminates in a
product. Read as a set, they establish what the practitioner consensus recommends, which
matters to Verdict less as a source of ideas than as the vocabulary an adopter will arrive
carrying.

### OWASP LLM Prompt Injection Prevention Cheat Sheet — cheat sheet

OWASP Cheat Sheet Series. `cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html`.

- Taxonomy well beyond direct/indirect: encoding and Unicode smuggling, typoglycemia
  ("ignroe"), HTML and Markdown injection, multi-turn session poisoning with delayed
  triggers, RAG poisoning, multimodal injection, and agent-specific thought/observation
  injection and tool manipulation.
- Primary defences, in the order given: input validation and sanitization; structured
  prompts separating instructions from data (citing StruQ, arXiv:2402.06363); output
  monitoring; human-in-the-loop; Best-of-N mitigation.
- Guardrails are placed at three distinct positions: **input screening**, **output
  screening**, and **action screening** — the last defined as "evaluate proposed tool calls
  against original user intent."
- The **dual-LLM pattern**: "A privileged LLM holds the tools but never reads untrusted
  content directly. A quarantined LLM reads untrusted content but cannot take action." With
  the caveat that "a guardrail LLM is itself an LLM and is itself susceptible to prompt
  injection."
- Its most valuable section is a candid inventory of what does not work. Best-of-N
  jailbreaking reaches "89% success on GPT-4o and 78% on Claude 3.5 Sonnet with sufficient
  attempts," and against it: "Rate limiting: only increases computational cost"; "Content
  filters: can be systematically defeated through sufficient variation attempts"; "Safety
  training: proven bypassable with enough tries"; "Circuit breakers: demonstrated to be
  defeatable even in state-of-the-art implementations"; "Temperature reduction: provides
  minimal protection even at temperature 0."
- Its conclusion from that: "Robust defense against persistent attacks may require
  fundamental architectural innovations rather than incremental improvements."

### Six vendor prevention guides — IBM, Teleport, Sweet Security, Imperva, Protecto, OffSec

IBM Think (`prevent-prompt-injection`), Teleport (`prevent-prompt-injection`), Sweet
Security (`ai-prompt-injection`), Imperva (`prompt-injection`), Protecto
(`how-to-prevent-prompt-injection`), OffSec (`how-to-prevent-prompt-injection`).

- The convergent list, recommended by five or six of the six: least privilege over tools and
  data; separation of instructions from data by delimiter or role; input filtering; output
  filtering or monitoring; human approval before irreversible actions; audit logging.
- Each piece then steers to its own product — governance platform, agent identity framework,
  runtime CNAPP, WAF/RASP, data masking, training. The convergence is real; the terminus is
  commercial, and no entry below rests on a vendor's claim about its own efficacy.
- IBM is the most useful of the six and the most candid: prompt injection is "a significant
  security flaw with no apparent fix"; "the only way to prevent prompt injections is to avoid
  LLMs entirely"; LLMs "cannot distinguish between commands and inputs based on data type."
  It works through parameterization, delimiters, self-reminders, input filters and classifier
  guardrails, and names the defeat for each — prompt-leakage attacks that recover the system
  prompt's syntax, completion attacks that circumvent delimiters, tree-of-attacks against
  structured queries, and classifier guardrails that are "themselves susceptible to
  injections because they are also powered by LLMs."
- Teleport contributes the only concrete incidents in the corpus: **EchoLeak
  (CVE-2025-32711)**, a zero-click indirect injection reaching Microsoft 365 Copilot through
  an external email and causing exfiltration, and hidden instructions in Google Calendar
  invites reaching Gemini. Its framing of the class is exact: "the attack does not bypass
  infrastructure controls. Instead, it steers autonomous systems operating with legitimate
  access towards committing the attack themselves."
- Sweet Security states Verdict's own thesis in the corpus's plainest terms: the
  **"instruction-to-action gap"** is the seam that matters, and "inspection that doesn't
  observe runtime behavior will always arrive one step too late."
- Protecto's "Context-Based Access Control," which "evaluates access at the moment the agent
  makes a request" rather than statically, is a renaming of a control Verdict already has.

**Primitive — a defence whose outcome depends on phrasing can be defeated by search; one
whose outcome does not, cannot.**
**Verdict:** `already implements` the structural answer; `should adopt` one scoping sentence.

Every control OWASP lists as defeated by Best-of-N — attempt rate limiting, content filters,
safety training, circuit breakers, temperature reduction — sits at the model layer or the
attempt layer, where the outcome is a function of how the request was phrased. Search over
phrasings therefore wins, and the power-law scaling says only that patience is the price.
Verdict's authorization decisions are not in that class: the policy never reads the prompt,
which `docs/evaluation.md` already states from the other direction — the guarded denials are
"authorization denials — a property of Verdict's policy, deterministic regardless of
decoding." That is why greedy replays suffice to demonstrate them, and it is the strongest
external argument yet found for placing the enforceable boundary at the tool call.

The scoping sentence is owed because not every Verdict surface is phrasing-independent, and
the corpus makes the distinction citable. `requiresConfirmation()` resolves to a human who
can be socially engineered; the intent lever guarantees "a pre-mutation record, not an
outcome record" (`docs/limitations.md`); and the suite v2 filtered-permit run recorded nine
trials over-restricted *on phrasing*. `docs/evaluation.md` carries its adaptive-adversary
caveat against attack packs ("not a guarantee against an adaptive adversary"), and carries
the i.i.d. caveat against the rule-of-three bound — but the i.i.d. caveat is about
correlation among draws, not about an adversary who *optimizes* the draw. One sentence in
that paragraph, naming which guarded denials a phrasing search cannot move and which it can,
would close the gap between the two caveats.

**Primitive — rate-limiting attempts is defeated by patience; rate-limiting effects is not.**
**Verdict:** `already implements`, with a wording risk worth removing. OWASP's "rate
limiting: only increases computational cost" is a true statement about bounding *attempts*,
and a reader arriving from the cheat sheet may map it onto `rateLimit()`. ADR 0001's semantic
limit is the other kind — it counts "an application-defined action, such as refunds per actor
per day, rather than a provider-specific proxy" — and `docs/security-model.md` already gives
the adversarial sizing rule ("size that bucket for what an attacker can accomplish during its
window, not expected legitimate traffic"). A bound on effects is indifferent to how many
attempts the attacker spends reaching it. One contrastive clause makes that legible.

**Primitive — you cannot parameterize the prompt; you can parameterize the tool call.**
**Verdict:** `already implements`, and this is the corpus's best single sentence about why
the library exists. IBM works through why parameterization — the fix that closed SQL
injection and XSS — is "difficult if not impossible" for LLM inputs, then concedes the
opening: "while it is hard to parameterize inputs to an LLM, developers can at least
parameterize anything the LLM sends to APIs or plugins." Verdict is that parameterization,
and ADR 0025 is its literal form: a context-resolved target does not come from the model, so
an injected argument cannot move it, which the storefront differential demonstrates
(`contextResolvedTargetDifferential()`). Worth borrowing in `docs/security-model.md`, which
currently opens on mechanism rather than on the premise.

**Primitive — action screening is a distinct guardrail position, not a variant of input
screening.**
**Verdict:** `already implements`. OWASP's three placements — input, output, action — name
the third as evaluating "proposed tool calls against original user intent," which is #160's
intent lever. External corroboration that the placement is its own layer is worth recording,
as is the accompanying caveat: OWASP's action screener is an LLM judge and so "is itself
susceptible to prompt injection," where Verdict's is a policy decision. That difference is
exactly the one `docs/limitations.md` already draws in bounding the lever to a pre-mutation
record rather than an outcome — the lever's honesty about what it cannot conclude is what
distinguishes it from a judge that will always return an answer.

**Primitive — human review triggered by prompt keywords binds to the wrong thing.**
**Verdict:** `intentionally rejects`. OWASP's human-in-the-loop layer flags requests
"containing keywords like 'password,' 'api_key,' 'admin,' 'bypass'." That is a prompt-layer
filter wearing a human's clothes: it fails to the same search that defeats every other
prompt-layer filter, and it fires on benign text. `requiresConfirmation()` binds to the
capability and the server-resolved target — to what the action does, not to how it was asked
for — which is why a rephrasing cannot route around it. Recorded because a reader arriving
from the cheat sheet may expect the keyword shape and read its absence as a gap.

**Primitive — human approval is itself an attack surface.**
**Verdict:** `already implements`; a **fifth** independent source on
`confirmation-fatigue-guidance`. IBM is alone in the corpus in stating the defeat: "attackers
can use social engineering techniques to trick users into approving malicious activities,"
and that human-in-the-loop "makes using LLMs more labor-intensive and less convenient." The
other five recommend human approval with no such caveat. Verdict's answer is ADR 0026 and the
three `docs/security-model.md` sections it produced — what an approver is shown about a
proposal's origin, declaring where a proposal came from, and denying unattributable
proposals — which treat the approver as a party that can be deceived rather than as an oracle.

**Primitive — an agent needs a distinct identity, and the audit record must carry it.**
**Verdict:** `already implements` the evidence half. Teleport's list — per-agent identities
rather than shared service accounts, ephemeral least-privileged credentials, authorization
enforced at execution time, and audit carrying identity, role, and target — reaches from a
fourth direction the conclusion section 11's `DecisionEvidence records no actor` finding
reached, and which `actorFingerprint` and `subjectFingerprint` have since satisfied. The
credential half is the application's: Verdict authorizes an actor it is handed and does not
issue, scope, or expire credentials.

---

**Surveyed, no hook.** **Input sanitization, delimiters, structured prompts (StruQ),
typoglycemia and encoding detection, and classifier guardrails**: prompt-layer controls in a
library that deliberately holds no prompt-layer surface, and each is conceded bypassable by
the source recommending it. **Output filtering and DLP-style masking** (Protecto's tokenized
vault, Imperva's WAF/RASP, IBM's EDR/SIEM/IDPS): adjacent product categories, not
capability-boundary controls. **The dual-LLM pattern**: an application's model topology,
which Verdict does not own — though it is worth noting that the pattern is a convention until
something enforces the privileged half's tool boundary, which is the role Verdict plays.
**Protecto's Context-Based Access Control**: ADR 0003 and ADR 0013 under a vendor name.
**OffSec's curriculum and CI/CD red-team integration**: training, not design. Also surveyed:
the corpus's shared least-privilege and audit-logging recommendations, which Verdict
implements throughout and which no entry above needed to restate.

**Citation hygiene.** EchoLeak's identifier (CVE-2025-32711) and the Gemini calendar-invite
case reached this log through a vendor blog and have not been checked against a primary
advisory. Neither should appear in a published Verdict document until it has been —
section 8's finding that the stale-citation problem is a chain-of-custody problem applies to
this entry's own sources.

## 14. The Agent Passport System corpus

Nine Zenodo deposits and one IETF individual submission, all by Tymofii Pidlisnyi
(AEOESS), published February–July 2026. They are one research programme rather than ten
independent results: the Agent Passport System (APS), a cryptographic delegation protocol
whose organising invariant is *monotonic narrowing* — delegated capability may be
attenuated, never amplified. APS is the nearest neighbour to Verdict that this survey has
found, and it reaches several of Verdict's conclusions from a different starting point,
which is what makes it worth reading closely.

### Monotonic Narrowing for Agent Authority — preprint, and draft-pidlisnyi-aps-00

Zenodo `10.5281/zenodo.18932404` (2026-03-10); IETF `draft-pidlisnyi-aps-00` (2026-03-27).

- Eight invariants over an abstract state model. INV-2 (scope narrowing) and INV-3 (spend
  narrowing) are the delegation-attenuation property; INV-4/INV-5 give cascade revocation
  and revocation irreversibility; INV-8 requires that "every active delegation chain traces
  to a human principal."
- INV-6, *three-signature completeness*: "no execution receipt exists without a
  corresponding intent and policy decision" — an agent-signed `ActionIntent`, an
  engine-signed `PolicyDecision`, and an agent-signed `PolicyReceipt`.
- The architectural claim is stated in Verdict's own terms: "the LLM sits in the advisory
  path, not the deterministic gate. The deterministic gate's verdict is final." The authors
  name the failure they designed away from as the "LLM-in-the-middle" problem — "a
  non-deterministic component producing cryptographic attestations."
- The paper is unusually candid about its own limits: it maps itself against the OWASP AIVSS
  taxonomy as "5 strong, 3 partial, 2 weak," presents ten adversarial scenarios "including 2
  expected failures," lists fifteen known limitations, says plainly "we do not claim
  machine-checked proof of implementation correctness," and grades its own enforcement as
  "weak under voluntary SDK."

### Faceted Authority Attenuation: A Product Lattice Model — preprint

Zenodo `10.5281/zenodo.19260073` (2026-03-27).

- Generalises the scalar invariant: authority is an element of a **product lattice** over
  seven constraint dimensions (scope, spend, depth, time, reputation, values,
  reversibility), and delegation is a monotone function on it. Corollary 1 gives independent
  per-facet narrowing; the earlier three-condition formulation is the n = 3 case.
- Three claimed benefits unavailable to a scalar model: structured denial diagnostics naming
  which dimension bound; a signed `AuthorizationWitness` capturing lattice position at
  execution time, recording "which constraints were checked, what headroom existed, and
  which dimension was the binding constraint"; and **near-miss detection** on
  operator-configured proximity thresholds.
- The witness "is generated by the enforcement gateway (not self-reported by the agent)."
- The motivating example is a facet interaction a scalar system cannot express: within
  budget, but temporally fragile.

### The Evidence-Safety Gap in Cryptographic Agent Governance — preprint

Zenodo `10.5281/zenodo.19914628` (2026-04-30).

- Characterises an action as ⟨I, D, P, A, R, W, E⟩ and observes that a governance system
  implements a procedural validity predicate `Valid(I, D, P, A, R)` while the property
  anyone actually cares about is `Safe(E(A, W))` over world-state W. The Evidence-Safety Gap
  is the omitted-variable distance: **Valid ⇏ Safe**.
- A **compliance-complete failure** is the simultaneous condition of procedural validity and
  unsafe effect. Presented as "a definitional observation rather than a theorem" — if Valid
  does not depend on W and Safe does, the failure class is immediate.
- W decomposes into five candidate classes: semantic, population, trust, pipeline, temporal.
  Pipeline is the composition case — "a pipeline of agents, each acting compliantly,
  composes to produce an action that would have been refused directly. No deputy is
  confused; the violation exists only in composition."
- Two design implications, neither of which closes the gap: **claim-scoped receipts** ("every
  cryptographic artifact should explicitly state what it proves and what it does not prove")
  and **authorization-effect separation** ("treating an authorization receipt as effect
  evidence is the structural source of several failure patterns").
- The institutional caution is the paper's sharpest sentence: "the danger comes from
  strength, not weakness: receipts verify cleanly, so institutions may overuse them."

### Plausibly wrong: peer-voted retention can fall below random — preprint

Zenodo `10.5281/zenodo.21208555` (2026-07-05).

- A controlled isolation of the retention rule in populations of short-lived LLM agents:
  identical deposit streams through six retention rules under a hard eight-slot cap, five
  independently generated pools, with kill conditions, priors, and budgets pre-registered
  and hashed before any run.
- Peer voting kept a worse shared memory than random selection in four of five replicates
  (mean truth 0.475 against 0.700). The mechanism is the finding: "voting selects for
  plausibility, and plausibility is the one property correct and incorrect entries share, so
  the junk it keeps is fluent by selection."
- All three rules with access to a deterministic verifier held truth at 1.000 in every
  replicate; all three without one degraded in every replicate.
- A second pre-registered sweep corrupted the verifier itself, showing the property degrades
  smoothly with no cliff: anchored retention still beats random with a coin-flip verifier
  (truth 0.742 at p = 0.50) and crosses below random only near p ≈ 0.40.

**Primitive — procedural validity is not effect safety, and an artifact must say which one
it proves.**
**Verdict:** `already implements`, and this is the closest match to an existing Verdict
design that any survey in this log has produced. [ADR 0028](adr/0028-claim-type-is-a-curated-public-vocabulary.md)
§3 states the rule the paper derives from its formalism: "a label never implies execution, a
downstream receipt, or a resulting state," and it names the strongest execution-adjacent
label as "Verdict marking its own claim complete around a successful return — an
admission-side belief, never a receipt from the executor, carrying no result."
`docs/security-model.md`'s "What a decision record asserts, and how to cite one" exists to
prevent exactly the inferential drift the paper describes. Two systems reached the same rule
from different directions — APS from a formal decomposition of the validity predicate,
Verdict from asking what a record is entitled to assert — which is the strongest kind of
corroboration a design choice can get.

What the paper adds is vocabulary — *compliance-complete failure*, Valid ⇏ Safe, the
omitted-variable decomposition — and one caution Verdict's documentation does not make:
**amplification**. `docs/limitations.md`'s "Verification is the control, not the chain alone"
is adjacent, but it is about verification mechanics, not about an institution treating a
cleanly verifying receipt as evidence of a safe outcome and shifting the burden onto the
harmed party. One sentence, in that section or beside the claim-type vocabulary.

**Primitive — the five omitted-variable classes are a checklist to audit a limitations
document against.**
**Verdict:** `already implements` three; `should adopt` the other two as stated boundaries.
W-temporal is [ADR 0003](adr/0003-execution-target-freshness.md) and the receipt
revalidation that ADR 0031 scopes. W-semantic is "Authority is not intent," in both
`docs/security-model.md` and `docs/limitations.md`. W-pipeline is "Shared buckets are
composition bounds," where Verdict already concedes it "has no native model of an attack
composed from individually permitted actions" and offers a volume bound that "caps blast
radius, not intent or selection" — the paper's mitigation list (intent binding, composite
audit, taint tracking, pipeline-level authorization envelopes) is a candidate set for
[ADR 0010](adr/0010-future-semantic-limit-meters.md)'s future meters.

**W-population and W-trust have no Verdict analogue at all.** Verdict authorizes one action
for one actor against one target, and holds no model of a population of agents or of a
counterparty's reputation. That is not a gap to close — it is a boundary to state, and
`docs/limitations.md` currently states neither.

**Primitive — authority as a product lattice, with narrowing monotone in each facet
independently.**
**Verdict:** `should investigate`, gated on a decision already deferred.
[ADR 0015](adr/0015-authority-propagation.md)'s Invariant D1 — "the effective authority at
hop n must be a subset of the effective authority at hop n−1" — is the scalar form of INV-2
and INV-3, and it is deliberately stated "as a constraint on future work" because Verdict
does not model multi-hop identity today. The lattice formalism is the shape D1 should take
*if* that changes: it is what lets a denial say which dimension bound, and it expresses facet
interactions ("within budget but temporally fragile") that independent per-gate checks
cannot. Worth citing in ADR 0015 as the known generalisation rather than adopted now.

**Primitive — warn on proximity to a constraint boundary, before the violation.**
**Verdict:** `should investigate`. **Candidate:** `near-miss-signal`.

This is the one concrete unimplemented idea in the corpus, and Verdict is most of the way to
it already: `rate_limit_limit`, `rate_limit_remaining`, and `rate_limit_reset_at` are
recorded fields on `DecisionEvidence` (`src/Evidence/DecisionEvidence.php`), populated from
`RateLimitManager`'s decision metadata. The headroom the paper's witness records is already
in Verdict's evidence. What is absent is an operator-configured threshold and a signal.

Verdict has faced this precise question once and deferred it on the record: the
tool-description divergence is "a forensic signal, not an authorization control ... Recording
a divergence does not deny, warn, or dispatch an event — whether it should is a separate
decision, deliberately not made here." Near-miss alerting is the same question about a
different facet, and the same answer may well be right. The data, the precedent, and the home
(ADR 0010) all exist; only the decision is missing.

**Primitive — the authorization record is written by the boundary, not self-reported by the
agent.**
**Verdict:** `already implements`. The faceted paper states the property directly — the
witness "is generated by the enforcement gateway (not self-reported by the agent)" — and
supplies the reason to hold it: a record's value in a dispute comes from its independence
from the party whose action it describes. Verdict's evidence is boundary-written throughout,
and this is a clear external statement of why that matters.

**Primitive — the deterministic gate is final; the model is advisory.**
**Verdict:** `already implements`. "The LLM sits in the advisory path, not the deterministic
gate" is Verdict's thesis, reached independently, and the "LLM-in-the-middle" naming — a
non-deterministic component producing cryptographic attestations — is a useful handle for
why `docs/evaluation.md` scores against the boundary rather than a model judge, and why the
intent lever is bounded to a pre-mutation record rather than an outcome judgement.

**Primitive — a selection rule that optimises for plausibility keeps fluent junk; only a
verifier holds truth.**
**Verdict:** `should adopt` as a citation. Verdict's evaluation harness already refuses the
model-judge design, but asserts that choice on reasoning alone. "Plausibly wrong" is an
experimental result behind it, and a stronger one than the bare claim: the three
verifier-backed rules held truth at 1.000 in every replicate while all three judged rules
degraded, and the corruption sweep shows the property degrades gracefully rather than
cliff-edged — a coin-flip verifier still beats random selection. The mechanism transfers
directly: plausibility is the property correct and incorrect entries share, which is why a
judge cannot separate them and a verifier can. Worth citing beside `docs/evaluation.md`'s
no-judge choice.

**Primitive — publish an honestly graded coverage map against an external taxonomy.**
**Verdict:** `should investigate`. APS grades itself against OWASP AIVSS as "5 strong, 3
partial, 2 weak," names two of its ten adversarial scenarios as expected failures, and marks
its own enforcement "weak under voluntary SDK" — the same limitation Verdict states as "no
protection for bypassed paths." Verdict maps to no external taxonomy today. A graded
crosswalk would be legible to adopters arriving from OWASP vocabulary, and the honest-grade
form is one this repository could execute better than most. The caution is the one the
compatibility matrix already taught: a coverage map written as a standing claim acquires a
maintenance obligation, so it should be written as a dated snapshot against a named taxonomy
version.

---

**Surveyed, no hook.** **The Agent Social Contract** (`10.5281/zenodo.18749779`): the LOKA
ranked value system, the Agora communication protocol, and beneficiary economics —
value distribution and ethical ranking are outside a library that authorizes one action at a
time. **Physics-Enforced Delegation** (`10.5281/zenodo.19478584`): quantum backend fidelity
as delegation facets, denying a backend whose T1 falls below threshold; the transferable
shape — a measured environmental fact at decision time entering authorization and being bound
into the record — is ADR 0003 plus ADR 0017 already. Its incidental figure is worth keeping:
4.2 ms for policy evaluation and receipt signing, a comparable-system overhead datum for the
kind of measurement `docs/benchmarks.md` does not currently take. **From Access to
Derivation** (`10.5281/zenodo.19476002`): behavioral derivation rights, governing what an
agent may *learn* from authorized access. Recorded here mainly to prevent a term collision —
Verdict's declared derivations are provenance lineage over records, while the paper's are
rights over what may be learned — two distinct primitives that happen to share a word.
**Governance in the Medium**
(`10.5281/zenodo.19582550`): a working paper arguing that the unit of governance is the
population of sessions rather than the agent, and naming its own central open problem —
that cryptography formalizes authorship, not meaning. Its most concrete transfer, that
"artifact-based state with signed authorization" makes authority survive session death, is
what Verdict's durable receipts and execution claims already do in a different vocabulary.
**Cognitive Attestation** (`10.5281/zenodo.19646276`): signing sparse-autoencoder feature
activations alongside an action record. It addresses a layer Verdict deliberately does not
reach — `docs/limitations.md` states "no provider-internal inspection" as a scope boundary —
and the composition target the paper names (SCITT) is covered in section 7.

**Citation scope.** The entries above draw on the corpus's definitions and formalisms, which
stand on their own terms. Its experimental results are cited as reported, and should be
attributed that way in any Verdict document rather than restated as settled findings — the
same standard section 8's chain-of-custody finding sets for every source in this log.

## 15. Research sweep 2026-09 — attack surfaces, injection realism, over-refusal, evaluation

A sweep of recent agent-security literature and neighbouring systems, read for its bearing on the
attack packs, the injection-realism program, the over-restriction facet, and the live harness. As
elsewhere in this log, experimental figures are cited as reported, not restated as settled findings.

### Capability Gates Are Not Authorization: Confused-Deputy Failures in LLM Agent Frameworks — paper (arXiv)

**Primitive — per-call argument-value authorization vs. capability gating.** Exposing a tool (capability
gating) is not the same as authorizing *this* call's concrete arguments; a confused deputy runs the
identical call the gate admits. The paper reports auditing several mainstream agent frameworks and
finding they gate capability exposure but do not re-authorize each model-emitted call against its
argument values, and that an argument-level gate it implements denies the calls they admit.

**Verdict:** `already implements`. This is the founding structural-vs-semantic split (§1): Verdict
authorizes a *resolved target* derived from the call's arguments on every protected execution
(`VerdictManager::evaluate()` → `CapabilityAuthorizer::decide()` against the refreshed target), not tool
exposure. The finding is valuable as *external* corroboration of a claim Verdict otherwise self-asserts.
Attribute it as reported, and — if any Verdict document draws the framework contrast — cite each
comparator's own primary documentation for what it does, not this paper's cross-framework inference.
No "the differentiator" language: state what Verdict authorizes, and let the comparison rest on cited
primary sources.

### CaMeL: Defeating Prompt Injections by Design — paper

**Primitive — capabilities attached to values (provenance as an authorization input).** Track where a
value came from (taint from untrusted tool output) and let policy predicate on that origin, not only on
the value's shape.

**Verdict:** `should investigate`. Verdict's argument predicates see a value, not its provenance, so
they cannot distinguish a user-supplied id from one lifted out of an injected search result. Distinct
from #201 (which *discloses* cross-invocation lineage to approvers): this would make provenance a
policy *input*, with privacy, completeness, spoofing, and fail-closed semantics to settle first.

**Candidate:** `provenance-as-policy-input` (filed as #476).

### LLMail-Inject, and CommandSans — datasets/paper (arXiv)

**Primitive — argument-level breach observables over realistic injected content.** A realistic corpus
places injections in tool *results* synthesized from real tool schemas with annotated user-controlled
slots (CommandSans), and grades the breach at the *argument* level — a legitimate call with an
attacker-chosen recipient/body is the breach (LLMail-Inject), with per-stage labels only where each
stage is observable.

**Verdict:** `should adopt`. The shipped `search-argument-exfiltration` case proves the argument-predicate
mechanism on a narrow canary; these give the realism and the exfil shapes to broaden it into a proper
pack, recording *unmeasured* rather than inventing a `detected` stage the boundary cannot see.

**Candidate:** `realistic-injection-exfil-pack` (filed as #474).

### Semantic / rate abuse — sweep-promoted coverage gap

**Primitive — a sequence of individually-permitted calls that breaches an aggregate limit.** Each call
is fine; the volume is the abuse.

**Verdict:** `should adopt`. Verdict ships the rate-limit / Throttle / filtered-permit machinery
(`docs/evaluation.md`) but no pack case drives an abuse sequence through it — the clearest current
"mechanism exists, coverage absent" gap. Two-sided oracle: the abusive sequence stops at the boundary
and a benign burst within the limit still completes.

**Candidate:** `semantic-rate-abuse-case` (filed as #475).

### Mind the Gap: Time-of-Check to Time-of-Use, and Atomicity for Agents — papers

**Primitive — a resource can change between the checked call and the used call behind an unchanged
argument.** A swapped resource is invisible to per-call argument inspection alone.

**Verdict:** `already implements` (detection) / `should adopt` (enforcement). Check-to-use digest binding
is detected today (#295, shipped, v0.13.0); turning a detected mismatch into a fail-closed decision is
the open enforcement work (#386).

### AgentHarm — benchmark

**Primitive — a matched benign twin of equivalent tool-use complexity per attack.** The utility arm is
structural per-case, not a separate pack-wide measurement.

**Verdict:** `already implements` (two-sided harness) / `should adopt` (as a per-case coverage rule). The
harness already separates `CasePurpose::Security` from `Utility` with a paired unguarded control and a
two-sided filtered-permit oracle (`docs/evaluation.md`); the transferable discipline is requiring the
benign twin *per case*.

**Candidate:** `benign-twin-per-case` (checklist on #213).

### Establishing Best Practices for Building Rigorous Agentic Benchmarks (ABC), and abliterated-model instruments — papers

**Primitive — outcome validity: an "attack failed" arm must not pass on a model that does nothing, and a
zero-breach result must not be an artifact of a degraded attacker.** ABC documents the TAU-bench flaw
(an empty response scored as success); the abliterated-instrument work documents attacker-competence
confounds.

**Verdict:** `already implements`. Filtered-permit declarations require both inclusion *and* exclusion
assertions, so an empty result cannot pass; the 2×2 classifies a do-nothing guarded arm as
`over_restricted`, never a breach-prevented. The live methodology already runs an abliterated,
tool-capable instrument and reports the alignment confound (#170). Recorded so the guarantee is
attributed to these external standards rather than asserted.

### AI Agents That Matter — paper

**Primitive — model-developer vs. downstream-developer evaluation.** Benchmarking a model's capability is
not the same question as whether a specific application's boundary holds.

**Verdict:** `should adopt` (positioning). Verdict's packs are downstream-developer evaluation —
does *my* application's authorization boundary hold — not model benchmarking. This framing is not yet
a shipped statement; it is worth stating in
`docs/evaluation.md` to preempt "why not just use AgentDojo": AgentDojo measures a model/defense against
attacks; a Verdict pack measures whether one application's declared capabilities deny what they should.
No claim that it replaces model benchmarking.

### ETDI: Tool Squatting and Rug-Pull in MCP — paper

**Primitive — versioned tool identity as an authorization input.** A tool whose definition changes between
registration and invocation should be treated as a new, unapproved tool.

**Verdict:** `already implements` (forensic detection) / `should investigate` (enforcement, in #386's
scope only). Verdict records configured-vs-invocation tool-description fingerprints and their match result
in evidence (`add_tool_description_fingerprints_to_verdict_evidence_table`). Deny-until-reapproved on a
mismatch is a materially new enforcement proposal; it belongs to #386 if deliberately added there, not as
its own item.

### Multi-Agent Systems Execute Arbitrary Malicious Code — paper

**Primitive — a delegated sub-agent call's principal and scope at the boundary.** Does a delegated call
inherit the orchestrator's authorization or carry its own narrower scope, and how is call provenance
attributed?

**Verdict:** `should investigate`. The same seam as the laravel/ai invocation-id defect (PR #53); the
shipped `DelegationConfusionAttackPack` already has the sub-agent case pending on #201. No standalone
principal model until Laravel AI can preserve the invocation/provenance boundary the pack needs.

**Candidate:** `subagent-principal-scope` (checklist on #213).

### Surveyed, no hook

- **Measuring Security Without Fooling Ourselves** (temporal staleness) — Verdict already versions attack
  cases and suites, commits baselines, and compares in CI; a corpus "decay policy" is a dated docs
  convention, not new machinery.
- **Indirect Prompt Injection in the Wild** and **LlamaFirewall / AgentDojo variant** — measured
  in-the-wild prevalence and a technique×threat-category matrix are an external empirical program; kept as
  a dated taxonomy crosswalk in this log, not restated as weighted facts.
- **Measuring Real-World Prompt Injection in Resume Screening** — in that study, most observed "injection"
  was fabricated *content with no instructions* that corrupts model judgment without producing an
  unauthorized tool call. That class is outside Verdict's authorization boundary by construction: Verdict
  decides tool calls, not model belief (the boundary `docs/limitations.md` records). Verdict's
  context-release controls address the disclosure subset, so the harm is not uniformly unobservable —
  avoid calling it "invisible" without that qualifier.
- **WASP** (partial-hijack observable), **PHTest / FalseReject / MobileSafetyBench** (benign-side
  generation and taxonomies), **Adding Error Bars / If Nothing Goes Wrong** (clustered SEs, zero-numerator
  bound wording) — real but foundational refinements to the live harness; recorded as #213 checklist
  items, added once each observable/metric is defined rather than asserted early.
- **Progent** (dynamic mid-run policy narrowing) — `intentionally defers`: a different planning/control
  problem, not smuggled into this sweep.
- **MCP Authorization specification** — `out of repo` until Laravel AI grows an MCP tool transport seam;
  the coarse-scope-vs-fine-policy gap only becomes executable then.
- **ToolApprovalGuard** — the decide-before-vs-after-the-approval-pause ordering is a core approval
  lifecycle question for the existing approval/resumption design, not a new issue unless a concrete
  contradictory behaviour is found.
- **Laravel Tackle** — a plausible adopter/case study; a coding-agent pack (protected-path write,
  out-of-allowlist Artisan, spend-cap) waits on a Verdict-owned capability and reusable oracle (#213).
- **Authenticated Delegation and Authorized AI Agents** — positioning only; folds into the
  downstream-developer framing above.

**Citation scope.** Every experimental figure above (bypass/unauthorized-execution counts, prevalence
percentages, hijack rates) is the cited source's reported result and must be attributed to it, never
restated as a Verdict measurement. Where an entry reads `already implements`, the verdict is grounded in
the cited `src/` path, ADR, or shipped doc; where it cannot be, it is recorded as `should adopt` or
`should investigate` and carries a candidate slug, per this log's standard.
