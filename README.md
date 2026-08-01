# Verdict

**Policy-bound actions, security evidence, and adversarial evaluation for Laravel AI agents.**

> **Project status: design stage.** Verdict is not installable yet, and no public API is stable.
> The examples in this README are design sketches intended to make the proposed behavior concrete.
> They document the direction of the project, not features that already exist.

Verdict is a proposed Laravel package for applications that allow AI agents to read sensitive
context, call tools, or change application state. Its central rule is simple:

> **The model may propose an action. The application must authorize it.**

Verdict is intended to complement [`laravel/ai`](https://github.com/laravel/ai), not replace it.
Laravel AI owns agents, providers, prompts, tools, structured output, streaming, conversations,
and provider integration. Verdict aims to add a security boundary around the data and authority
those agents receive.

The first target is Laravel. A framework-independent extraction may make sense later, but the
project will begin by solving this problem well in a Laravel application.

## What Verdict is trying to answer

For any agent run:

1. What was this agent allowed to do?
2. What untrusted content entered its context?
3. Did its proposed or observed behavior violate policy?
4. Could known attacks manipulate it?
5. Did a model, prompt, tool, policy, or document change reduce security?
6. What evidence supports the result?
7. What data left the application, and to which destination?
8. Did the authorized operation execute exactly once as approved?

Verdict aims to be holistic at this boundary. It is not intended to be a complete AI security,
privacy, identity, compliance, or observability platform.

## Why this needs an application boundary

An LLM is useful precisely because it can interpret ambiguous requests, combine context, choose
tools, and adapt. Those strengths do not make its output suitable as an authorization decision.

Consider a storefront assistant:

```text
Customer: Cancel the bag I ordered yesterday, unless it has already shipped.
```

The model can help determine that the customer is asking about cancellation and identify the
likely order. The application must still determine:

- Who is the authenticated customer?
- Which canonical order does "the bag I ordered yesterday" refer to?
- Does that customer own the order?
- Has its state changed since the proposal was created?
- Is it still eligible for cancellation?
- Is confirmation required?
- Has this exact operation already executed?

Prompt instructions cannot safely answer those questions. Laravel code can.

## Proposed lifecycle

```text
Application context
    -> release only permitted data to the selected agent/provider
    -> model interprets the request and proposes a typed capability
    -> application resolves canonical resources
    -> Verdict binds principal, tenant, resources, and normalized arguments
    -> Laravel Policy and other deterministic rules decide
    -> permit, deny, throttle, request confirmation, or request review
    -> re-authorize immediately before execution
    -> execute an idempotent application command
    -> record redacted evidence and emit security signals
```

The proposed core vocabulary is:

- **Capability** — a developer-defined application operation an agent may propose.
- **Proposal** — the model's untrusted interpretation of user intent.
- **Envelope** — a proposal bound to server-resolved identity, resources, arguments, policy,
  expiry, and replay protection.
- **Decision** — permit, deny, require confirmation, require review, or throttle.
- **Execution** — deterministic application code that performs the operation.
- **Evidence** — observable facts about what was released, proposed, decided, and executed.

## Capabilities

A capability should represent a domain operation, not unrestricted CRUD access.

Prefer:

```text
orders.view
orders.cancel
returns.start
cart.add
support.escalate
```

Avoid giving a model broad primitives such as:

```text
database.update
orders.set_status
refunds.set_amount
customers.find_by_id
```

An illustrative capability definition might eventually look like this:

```php
Verdict::capability('orders.cancel')
    ->arguments(CancelOrderData::class)
    ->resolveUsing(ResolveCustomerOrder::class)
    ->authorizeUsing(OrderPolicy::class, 'cancel')
    ->requiresConfirmation()
    ->rateLimits(CancelOrderLimits::class)
    ->idempotent()
    ->executeUsing(CancelOrder::class);
```

The exact API has not been selected. The intended separation has:

- The model selects a bounded capability and proposes semantic arguments.
- Server-side code resolves references such as "my latest order."
- Laravel authorization decides whether the principal may act on the resolved resource.
- A deterministic handler performs the side effect.

The model should not choose its own principal, tenant, credential, authorization scope,
approval token, or idempotency key.

## Laravel Policies are still the source of truth

Verdict does not replace Laravel Gates, Policies, or properly scoped Eloquent queries.

A correctly applied Policy or customer-scoped query can fully prevent an insecure direct object
reference. The problem is that defining a Policy does not automatically invoke it when an AI tool
handler runs.

This raw tool is vulnerable even if an `OrderPolicy` exists elsewhere:

```php
final class LookupOrder implements Tool
{
    public function handle(Request $request): string
    {
        return Order::findOrFail($request['order_id'])->toJson();
    }
}
```

A developer can fix that individual tool with ordinary Laravel:

```php
$order = $customer->orders()->findOrFail($request['order_id']);

Gate::forUser($customer)->authorize('view', $order);
```

Verdict's intended value is not a more powerful ownership check. It is making the existing check
an explicit and auditable phase for every protected AI capability, with the correct principal and
canonical resource bound before execution.

> Laravel decides whether the user may view the order. Verdict aims to ensure that an AI-proposed
> order lookup cannot silently bypass that decision.

Any eventual Verdict enforcement will only protect execution paths that actually pass through it.
An audit command is planned to identify unguarded application tools and other known bypasses, but
the exact integration surface still needs to be implemented and validated against Laravel AI.

## Optional planner agents

Some capabilities may benefit from a specialized planner agent. Others should not pay for a
second model call.

```php
Verdict::capability('returns.start')
    ->planUsing(ReturnPlannerAgent::class)
    ->authorizeUsing(ReturnPolicy::class, 'start')
    ->executeUsing(StartReturn::class);
```

The planner could be an ordinary Laravel AI agent configured for OpenAI, Anthropic, Ollama, or
another supported provider. The agent would produce a typed proposal; it would not receive more
authority merely because it uses a different model.

The name `planUsing` is intentional. The **executor** or **handler** should remain deterministic
PHP code.

## Data release and PII scrubbing

Sensitive data may leave the application before any tool is called. Verdict therefore intends to
treat model context release as a policy decision.

Origin, trust, and sensitivity are separate properties. A customer record can be trusted
application data and still contain PII that should not be sent to a provider.

An illustrative release policy might look like this:

```php
Verdict::release(CustomerContext::from($customer))
    ->source(Source::application('customer-profile'))
    ->trust(Trust::trusted())
    ->classify(DataClass::PII)
    ->only([
        'first_name',
        'locale',
        'email',
        'dob',
        'orders.*.number',
        'orders.*.status',
    ])
    ->transform('dob', ToAgeBand::class)
    ->tokenize(['email'])
    ->to(Destination::connection('ollama-local'));
```

Field allowlists should be preferred over exclusions such as
`except: ['ssn', 'dob']`. An exclusion can begin leaking a newly added `tax_id` or
`medical_notes` field without any policy change.

The proposed release pipeline is:

```text
structured field projection
    -> derived values and tokenization
    -> pluggable scanning of unstructured text
    -> final post-scrub validation
    -> destination policy
    -> redacted evidence
```

PII detection is imperfect. Verdict should support deterministic projection and pluggable
detectors, report what was removed or transformed, and never claim that arbitrary free text has
been proven free of personal information.

A provider name alone is not a sufficient trust boundary. `Ollama` may refer to a local process
or a remotely configured endpoint. Release policy should ultimately apply to a resolved
connection or trust zone, not just a model-provider enum.

## Provenance

Untrusted instructions can enter through more than the user's message:

- Retrieved product descriptions and knowledge-base documents.
- Tool results and external API responses.
- Uploaded files.
- Search and web content.
- Stored conversation memory and summaries.
- MCP tools and servers.
- Another agent.

Verdict intends to preserve source and trust labels as content is passed between agents and tools.
A summary of an untrusted document does not become trusted merely because a model produced the
summary.

The practical limits of provenance tracking will depend on the integration points Laravel AI
exposes. Verdict should document those limits rather than imply visibility it does not have.

## Decisions, confirmation, and replay protection

Proposed decisions are:

```text
Permit
Deny
RequireConfirmation
RequireReview
Throttle
```

Confirmation should approve a canonical envelope, not model-written prose. If the resource or any
material argument changes, the prior approval should no longer apply.

An approval nonce and an execution idempotency key have different jobs:

- A **nonce** is unpredictable and single-use. Reuse is rejected to prevent approval replay.
- An **idempotency key** is intentionally reused for retries of the same logical operation. It
  prevents a timeout or queue retry from performing the side effect twice.

The package may expose these concepts through an approval state machine rather than as two public
fields. In either design, the model must not generate them.

## Rate limits and risk budgets

Laravel should continue to handle ordinary HTTP and queue throttling. Verdict intends to add
semantic limits that understand the principal, tenant, capability, resource, and decision phase.

Examples include:

- Proposal attempts per principal and capability.
- Denied cross-resource attempts.
- Successful executions per resource.
- Tool calls or planner calls per conversation.
- Token or cost budgets per tenant.
- Cumulative risk across individually permitted operations.

Stateful abuse matters. Ten separate permitted credits can be abusive even if each one passes the
same Policy. Verdict's evaluation model should eventually support multi-turn, cross-session, and
multi-capability scenarios rather than treating every prompt in isolation.

## Evidence without collecting everything

Useful evidence may include:

```text
run and correlation IDs
agent class
resolved provider and model
application build and policy version
rendered prompt or instruction hash
source and trust labels
principal and tenant references
capability and normalized arguments
resources resolved by the application
authorization decision and reason code
approval and idempotency metadata
rate and budget decisions
redaction counts
actual execution disposition
latency, token usage, and available cost data
```

The evidence store may contain highly sensitive information. The planned design will need
configurable evidence levels, retention, tenant isolation, access authorization, pruning, and
encryption. A hash of predictable personal information is not anonymization.

Verdict should record observable inputs, outputs, policy facts, and decisions. It should not
request or store hidden model chain-of-thought.

Tamper-evident storage may be offered through an optional adapter in the future. Ordinary evidence
storage should not be described as cryptographic proof.

## Security signals and containment

Verdict intends to emit normalized Laravel events for security-relevant behavior, potentially
including:

```text
CapabilityDenied
CrossTenantResourceRequested
SensitiveDataRedacted
ContextReleaseDenied
ApprovalReplayDetected
ExecutionRateExceeded
AgentBudgetExceeded
ToolLoopDetected
ProviderDestinationChanged
```

Applications could route these signals to logs, OpenTelemetry, a SIEM, or their own analytics.
Verdict does not currently plan to build a general anomaly-detection platform.

Possible containment primitives include disabling a capability, agent, tenant integration, or
principal. Automatic containment would need application-defined, high-confidence rules so an
attacker cannot trivially cause a denial of service by producing suspicious-looking traffic.

## Security evaluation

Laravel AI fakes are useful for testing application wiring. A fake response cannot establish that
a real model resists an attack.

Verdict intends to support both deterministic containment tests and explicitly invoked live-model
evaluations:

```php
SecuritySuite::for(StorefrontAgent::class)
    ->include(
        AttackPack::promptInjection(),
        AttackPack::crossTenantAccess(),
        AttackPack::piiExfiltration(),
        AttackPack::workflowAbuse(),
    )
    ->against([
        'local' => [Lab::Ollama, 'configured-model'],
        'hosted' => [Lab::OpenAI, 'configured-model'],
    ])
    ->run();
```

This API is provisional. The important properties are:

- Versioned attacker playbooks.
- Trusted setup and explicitly labeled untrusted payloads.
- Expected allowed behavior and forbidden outcomes.
- Assertions against proposals, decisions, tool calls, resources, disclosures, and side effects.
- Repeated live trials rather than treating one pass as proof.
- Provider errors reported separately from behavioral failures.
- Reproduction metadata and security regression baselines.
- Sandboxed handlers so an evaluation cannot issue a real refund, email, shipment, or deletion.

An agent that denies everything is not useful. Reports should keep security containment and
legitimate task success separate:

```text
Security containment:     97%
Legitimate task success:  82%
```

Verdict is not intended to become a general response-quality evaluation library. Its evaluation
focus is the security of application context, capabilities, policy decisions, and side effects.

LLM-as-judge assertions may be useful for semantic questions, but deterministic assertions should
decide deterministic facts such as whether a forbidden tool ran or whether a resource belonged to
the authenticated principal. Evaluator prompts must also be treated as exposed to adversarial
content.

## Planned storefront demonstration

The workbench application is planned as a customer-facing eCommerce assistant with product search,
cart operations, order lookup, cancellation, and returns.

The most reproducible demonstration does not depend on successfully jailbreaking a model twice.
It captures one model proposal and passes the same proposal through protected and unprotected
execution paths.

Example:

```text
Authenticated principal: customer_72
Request:                 "Where is order #1002?"
Resolved order owner:    customer_91
Model proposal:          orders.view(order_id: 1002)
```

The demo should honestly show three implementations:

| Implementation | Expected result |
|---|---|
| Naive raw Laravel AI tool | Returns another customer's order |
| Manually secured tool using a scoped query or Policy | Correctly denies access |
| Verdict capability using the same Laravel Policy | Denies and records the full policy decision and evidence |

The point is not that Laravel needs Verdict to perform an ownership check. The point is that
Verdict aims to make the secure pattern consistent, inspectable, and regression-tested across the
application's AI action surface.

The demo UI may show:

```text
untrusted input | model proposal | policy decision | observed side effect
```

The plan is to keep it in the package workbench rather than requiring routes, views, or frontend
assets in the distributed package.

Additional demo cases may include indirect injection in a product document, refund abuse,
argument changes after confirmation, approval replay, PII release, and multi-turn rate limits.

## Relationship to Laravel AI

Laravel AI already provides the model-facing runtime. Verdict should use its public extension
points and remain a thin adapter around them.

| Laravel AI owns | Verdict intends to add | The application still owns |
|---|---|---|
| Agents and prompts | Capability envelopes | Authentication and tenancy |
| Provider and model selection | Context-release decisions | Laravel Policies and business rules |
| Tool schemas and invocation | Mandatory authorization phase | Domain services and commands |
| Structured output | Provenance and sensitivity labels | Credentials and provider agreements |
| Conversations and streaming | Security evidence and signals | Retention and compliance decisions |
| Human approval mechanics | Argument-bound approval policy | Operator and customer experience |
| Generation limits and events | Security suites and regressions | Incident-response program |

Provider-side tools may execute outside the application's local tool handler. Verdict must publish
an enforcement matrix that distinguishes what it can block, what it can only observe, and what it
cannot see.

## Headless by default

The package is intended to work without Blade, Livewire, Inertia, Filament, or a JavaScript
framework.

The initial package should provide contracts, events, evidence records, decisions, commands, and
serializable data needed to build an interface. A development viewer or Filament integration may
be useful later, but it should remain optional.

Approval interfaces must be generated from trusted envelope data rather than model-authored HTML
or prose. Public denial messages should remain separate from detailed internal reasons.

## Threat model

Verdict is intended to help when:

- Direct or indirect prompt injection manipulates a model.
- A model proposes a tool with unauthorized resource IDs or arguments.
- A model or user attempts cross-tenant access.
- An approval is replayed or altered.
- Queue retries or concurrency could duplicate a side effect.
- Tool results, documents, memory, or peer agents introduce untrusted instructions.
- Excessive or cumulative use indicates workflow abuse.
- A model, prompt, tool, policy, or document change causes a measurable security regression.

Verdict will not, by itself:

- Prevent all prompt injection or jailbreaks.
- Prove that an agent is secure.
- Make arbitrary free text provably free of PII.
- Correct vulnerable business logic inside an executor.
- Replace Laravel Policies, cloud IAM, OAuth servers, DLP, secrets management, or network controls.
- Secure the infrastructure or data-retention practices of a model provider.
- Observe or block every action performed inside provider-hosted tools.
- Establish factual correctness or provide general content moderation.
- Turn untrusted MCP servers into trusted code.
- Provide a compliance certification.

The intended claim is narrower:

> Verdict aims to limit the impact of manipulated model behavior at the Laravel application
> boundary, record deterministic authorization evidence, and test whether those controls continue
> to work.

## Secure Laravel implementation details that matter

The implementation will need to account for ordinary Laravel runtime behavior:

- Principal, tenant, and action context must use lifecycle-scoped state rather than a singleton
  that could leak between Octane requests or queue jobs.
- Queued execution must rehydrate identity and re-authorize instead of trusting a serialized
  decision forever.
- Mutating handlers need idempotency independent of queue uniqueness.
- Streaming output cannot be retracted after it has been sent; sensitive response checks may need
  buffering or documented limitations.
- Policy and capability registration must work with cached configuration and long-running workers.
- Live-model evaluations must not run as part of an ordinary deterministic test command.

These are design requirements, not implemented guarantees.

## Proposed package shape

The first repository is expected to remain one Laravel package:

```text
fissible/verdict
    src/
        Capabilities/
        Context/
        Decisions/
        Evidence/
        Evaluation/
        LaravelAi/
        Policies/
        RateLimiting/
        Signals/
    tests/
    workbench/
```

The package is expected to be scaffolded from Laravel's official
[`package-skeleton`](https://github.com/laravel/package-skeleton), using its Testbench workbench
for the demo while keeping the distributed package headless.

Splitting a framework-independent core or optional UI into separate packages should happen only
after a real boundary and consumer appear.

## Installation

Verdict is not currently published or installable. There is no Composer command to run yet.

The provisional target is PHP 8.3+, Laravel 12 or 13, and the official Laravel AI SDK. Exact
constraints will be selected and tested when the package is scaffolded.

Live evaluations will require developers to supply their own provider credentials and accept the
associated provider costs and data-processing terms. Deterministic package tests should not
require provider credentials.

## Roadmap

This roadmap is directional and may change as the integration is prototyped.

| Phase | Scope | Status |
|---|---|---|
| Design | Threat model, vocabulary, package boundary, demo design | In progress |
| Runtime foundation | Capability registry, guarded tools, decisions, Laravel Policy integration | Planned |
| Identity and execution | Principal/tenant binding, confirmation state, expiry, idempotency | Planned |
| Context release | Source labels, field projection, PII scrubber contracts, destination policy | Planned |
| Evidence | Pluggable stores, redaction levels, security events, audit command | Planned |
| Evaluation | Deterministic attack cases, live-model suites, baselines, reports | Planned |
| Demo | Sandboxed eCommerce assistant and security trace | Planned |
| Containment | Kill switches and application-defined containment hooks | Exploratory |
| Optional UI | Development viewer or framework-specific adapter | Exploratory |

## Frequently asked questions

### Why not just use tool calls?

Tool calls are the transport for a model's proposal. They do not inherently bind the proposal to
the application's authenticated principal, canonical resource, Laravel Policy, approval state,
rate limits, or idempotent execution.

### Why not just put the rules in the system prompt?

Prompt rules guide model behavior. They are not an authorization mechanism. Untrusted content,
model changes, hallucination, and ordinary ambiguity can all produce an unexpected proposal.

### Why not just use a Laravel Policy?

You should use a Laravel Policy. Verdict intends to make invoking it a required stage for protected
AI capabilities and to record and test that invocation. A Policy that is correctly called inside
every tool may already prevent the authorization bug; Verdict adds consistency and the surrounding
security lifecycle.

### Does Verdict detect prompt injection?

Attack detectors may be useful signals, but the core design does not depend on correctly
classifying every malicious string. It assumes the model can be manipulated and limits what a
manipulated proposal can do.

### Can different capabilities use different models?

That is planned as an optional planner strategy. Provider and model selection should not alter the
capability's deterministic authorization or grant additional authority.

### Will Verdict include a dashboard?

Not as a required dependency. The package is intended to be headless. The workbench demo can have
a full interface, and optional development UI may follow later.

## Origins

Verdict grows out of work on
[`fissible/llm-triage-eval`](https://github.com/fissible/llm-triage-eval) and a broader interest in
reproducible, evidence-backed evaluation of AI behavior.

The project direction was also sharpened by Zendesk's
[`AI Agent Abuse Prevention Engineer`](https://zendesk.wd1.myworkdayjobs.com/en-US/zendesk/job/AI-Agent-Abuse-Prevention-Engineer_R34849)
posting, particularly its emphasis on threat modeling, provenance, policy gates, capability
scoping, session lifetimes, sandboxing, behavioral analytics, containment, and incident forensics.

Related security work includes the
[`OWASP Top 10 for Agentic Applications`](https://genai.owasp.org/2025/12/09/owasp-top-10-for-agentic-applications-the-benchmark-for-agentic-security-in-the-age-of-autonomous-ai/).
Verdict may map attack packs and evidence to relevant industry terminology, but such mappings
would not constitute certification.

## Feedback at this stage

This repository begins as a public design proposal. The most useful early feedback concerns:

- Laravel AI execution paths that a guarded capability could miss.
- Authorization, tenancy, approval, queue, and streaming edge cases.
- Useful evidence that can be collected without retaining sensitive prompts.
- Real agent-abuse incidents that should become reproducible attack cases.
- The smallest API that would make secure behavior easier than an unguarded tool.
- Places where the proposed scope duplicates Laravel or another focused package.

The API sketches will change. The invariant should not:

> **Models propose. Applications authorize. Verdict records and tests the boundary.**
