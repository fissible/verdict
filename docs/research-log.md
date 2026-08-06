
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
