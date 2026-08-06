
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
