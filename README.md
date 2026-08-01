# Verdict

**Policy-bound actions, security evidence, and adversarial evaluation for Laravel AI agents.**

> **Project status: early implementation.** The runtime authorization and verified-confirmation
> slices exist on `main`, together with a deterministic storefront workbench. Verdict has no
> tagged release and no stable public API. Sections labeled planned, proposed, or illustrative
> describe direction rather than shipped behavior.

Verdict is an early Laravel package for applications that allow AI agents to read sensitive
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

The implemented foundation is intentionally smaller than the eventual fluent API. A capability
currently names a Laravel ability and resolves the canonical policy target from an untrusted
proposal:

```php
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Fissible\Verdict\Facades\Verdict;
use Laravel\Ai\Tools\Request;

Verdict::capability(Capability::usingPolicy(
    name: 'orders.view',
    ability: 'view',
    resolveTarget: fn (ActionEnvelope $envelope): Order => Order::find(
        $envelope->proposal->arguments['order_id'],
    ) ?? throw TargetNotResolvable::make(),
)->executeUsing(function (AuthorizedAction $action): string {
    if (! $action->target instanceof Order) {
        throw new LogicException('Expected a bound order.');
    }

    return $action->target->toJson();
}));

final class StorefrontAgent implements HasTools
{
    public function tools(): iterable
    {
        return [
            Verdict::bound(
                definition: new LookupOrder,
                capability: 'orders.view',
                context: fn (Request $request): ActionContext => new ActionContext(auth()->user()),
            ),
        ];
    }
}
```

`BoundTool` delegates the existing Laravel AI tool name, description, schema, and approval
requirement, but never calls that tool's `handle()` method. At invocation time it:

1. Binds the server-provided actor and untrusted proposal into an envelope.
2. Resolves one canonical target.
3. Asks Laravel's Gate to inspect the target at the proposal stage.
4. Re-inspects the same in-memory target immediately before execution.
5. Passes an `AuthorizedAction` containing that target to the capability's deterministic executor.

Missing capabilities, expected target-resolution failures, missing executors, and either denied
authorization produce a recorded denial. Capability resolvers signal an expected missing or stale
target by throwing `TargetNotResolvable`; unexpected resolver and authorizer exceptions remain
application faults and are not mislabeled as policy decisions. Neither resolver nor authorizer
faults execute the action. The definition tool exists only to provide Laravel AI metadata; its raw
handler is not an execution fallback.

> [!WARNING]
> `GuardedTool` and `Verdict::guard(...)` help migrate your application's existing, pre-Verdict
> Laravel AI tools onto Verdict's authorization boundary without rewriting them. Do not use them
> for new security-sensitive capabilities. They authorize a resolved target and then delegate to
> an independent handler, so Verdict cannot establish that the handler acts on the same target.
> Use `BoundTool` for new capabilities.

The execution-stage check currently reuses the proposal-stage target object. It can observe
in-process changes to that object, but it does not reload external state and does not narrow a
database time-of-check/time-of-use race. Verdict does not yet define target identity, execution-stage
resolution, database transactions, or locking for arbitrary target types. Mutating executors remain
responsible for ordinary Laravel transaction, locking, and idempotency practices.

The model-provided tool-call ID is captured as an idempotency key in evidence. The confirmation
slice described below enforces expiring, single-use approval receipts for `BoundTool`. Verdict does
**not** yet enforce execution idempotency, review, or rate limits.

### Verified confirmations

`BoundTool` capabilities may require confirmation, but they must explicitly define the trusted
identity and state to which an approval is bound:

```php
Verdict::capability(
    Capability::usingPolicy(
        name: 'orders.cancel',
        ability: 'cancel',
        resolveTarget: fn (ActionEnvelope $envelope) => Order::find(
            $envelope->proposal->arguments['order_id'],
        ) ?? throw TargetNotResolvable::make(),
    )->requiresConfirmation(
        bindUsing: fn (ActionEnvelope $envelope, Order $order): array => [
            'actor_id' => $envelope->context->actor->getAuthIdentifier(),
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->getKey(),
            'order_version' => $order->updated_at?->getTimestamp(),
        ],
        reason: 'Confirm cancellation of this order.',
        ttlSeconds: 300,
    )->executeUsing(fn (AuthorizedAction $action) => CancelOrder::run($action->target)),
);
```

Verdict always includes the capability name and complete proposed arguments in the receipt
fingerprint. The binding adds identity and canonical resource state that Verdict cannot infer for
an arbitrary target. Include the principal, tenant, resource identity, and any state change that
must invalidate approval. Raw binding values are hashed rather than stored in the receipt.

Add `VerdictApprovalMiddleware` to an agent that resumes protected approvals:

```php
public function middleware(): array
{
    return [app(VerdictApprovalMiddleware::class)];
}
```

When Laravel AI returns a `PendingApproval`, resolve its Verdict challenge inside an endpoint that
has already authorized access to the conversation and pending call:

```php
use Laravel\Ai\Approvals\Decision as LaravelApprovalDecision;
use Laravel\Ai\Approvals\Decisions;

$challenge = Verdict::approvals()->challengeForToolCall($pendingApproval->id);

abort_if($challenge === null, 409);

$transition = Verdict::approvals()->approve(
    receiptId: $challenge->receiptId,
    toolCallId: $challenge->toolCallId,
    approvedBy: 'customer:'.auth()->id(),
);

abort_unless($transition->succeeded(), 409);

$response = $agent->prompt(Decisions::from([
    $pendingApproval->id => LaravelApprovalDecision::approve(),
]));
```

Use an opaque application identifier such as `customer:72` for `approvedBy`, not an email address
or other unnecessary PII. Verdict does not authenticate this string; the application endpoint must
authenticate the decision maker and authorize access to the conversation and pending action.

The database store advances a receipt from pending to approved to consumed under a transaction and
row lock. Execution requires all of the following: the unpredictable receipt ID was approved, the
receipt is unexpired, Laravel is resuming with an explicit approval for that tool-call ID, the
capability and full arguments still match, and the application-defined binding still matches.
Direct `handle()` calls, replay, changed arguments, expired receipts, edited approvals, and wildcard
approvals fail closed.

Verdict does not assume provider tool-call IDs are globally unique. The database uniqueness
boundary also includes the capability and exact binding fingerprint. A tool-call-only challenge
lookup returns no result when multiple retained receipts make it ambiguous.

This is an early synchronous Laravel AI integration. Streaming approval resumption is not yet
supported because agent middleware returns before a stream is consumed; protected execution will
fail closed without the scoped approval context. `GuardedTool` does not support verified
confirmation because its independent handler cannot be bound to the authorized target.

The longer-term fluent API remains an illustrative design:

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

That full API has not been selected. The intended separation has:

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

Verdict enforcement only protects execution paths that actually pass through `GuardedTool` or
`VerdictManager`. An unwrapped tool remains an ordinary Laravel AI tool and can bypass Verdict.
An audit command is planned to identify unguarded application tools and other known bypasses.

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

The current `BoundTool` slice implements these as an internal receipt state machine. The Laravel AI
tool-call ID identifies the pending call, while Verdict creates a separate unpredictable receipt ID
for the approval decision. The model generates neither the receipt ID nor the decision-maker
identity. Execution idempotency remains separate and unimplemented.

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

The initial implementation records a decision, evaluation stage, envelope ID, capability,
detailed internal reason, provider tool-call ID, timestamp, and a deterministic SHA-256
fingerprint of normalized arguments through an `EvidenceRecorder` contract. It does not store raw
arguments. A confirmed bound execution also records an approval-stage permit with a hashed receipt
reference. The default recorder is a no-op because silently choosing a storage destination or
retention policy would be unsafe; `InMemoryEvidenceRecorder` exists only for tests and local
development. It is unbounded process-local state and must not be used with production, Octane,
queue workers, or as a tenant-separated evidence store. Persistent evidence stores and execution
outcome records are not implemented yet.

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

## Storefront security lab

The workbench contains the first deterministic slice of a customer-facing eCommerce security lab.
It currently covers order lookup and cancellation; product search, cart operations, and returns
remain planned.

The most reproducible demonstration does not depend on successfully jailbreaking a model twice.
It captures one model proposal and passes the same proposal through protected and unprotected
execution paths.

Example:

```text
Authenticated principal: customer_72
Request:                 "Where is order #1001?"
Resolved order owner:    customer_91
Model proposal:          orders.view(order_id: 1001)
```

The implemented comparison honestly shows three implementations:

| Implementation | Expected result |
|---|---|
| Naive raw Laravel AI tool | Returns another customer's order |
| Manually secured tool using a scoped query or Policy | Correctly denies access |
| Verdict capability using the same Laravel Policy | Denies and records the full policy decision and evidence |

The point is not that Laravel needs Verdict to perform an ownership check. The point is that
Verdict aims to make the secure pattern consistent, inspectable, and regression-tested across the
application's AI action surface.

The demo UI shows:

```text
untrusted input | model proposal | policy decision | observed side effect
```

It lives entirely in the package workbench and does not add routes, views, or frontend assets to
the distributed package. It also demonstrates an argument-bound cancellation approval: changed
arguments fail, the exact approved action executes once, and replay fails.

The primary path intentionally uses a captured proposal rather than a live provider. Holding the
proposal constant makes the authorization comparison reproducible and requires no credentials.
An optional live-model path remains planned; it should feed its proposal into the same execution
comparison rather than treating successful exploitation as deterministic.

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

The confirmation receipt slice implements lifecycle-scoped approval context and database-backed,
single-use receipts. The remaining items are design requirements, not implemented guarantees.

## Package shape

The repository is one headless Laravel package:

```text
fissible/verdict
    src/
        Capabilities/
        Actions/
        Decisions/
        Approvals/
        Evidence/
        LaravelAi/
        Policies/
    tests/
    database/
    workbench/
```

The package is scaffolded from the conventions in Laravel's official
[`package-skeleton`](https://github.com/laravel/package-skeleton), using its Testbench workbench
for the demo while keeping the distributed package headless.

Splitting a framework-independent core or optional UI into separate packages should happen only
after a real boundary and consumer appear.

## Installation

Verdict is not published to Packagist and has no tagged release. While the repository is private,
authorized collaborators can install the development branch as a Composer VCS repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:fissible/verdict.git"
        }
    ]
}
```

```bash
composer require fissible/verdict:dev-main
```

The current constraints are PHP 8.3+, Laravel 12 or 13, and `laravel/ai` 0.10.2 or newer within the
0.10 line. Laravel AI is pre-1.0, so Verdict verifies its adapter against released public contracts
and should expect compatibility work as that SDK changes.

Live evaluations will require developers to supply their own provider credentials and accept the
associated provider costs and data-processing terms. Deterministic package tests should not
require provider credentials.

### Run the storefront security lab

The demo runs from Testbench and uses only synthetic data:

```bash
composer install
composer build
php vendor/bin/testbench serve
```

Open the displayed local URL. The default cross-customer scenario compares a naive Laravel AI
tool, an explicitly secured Laravel implementation, and Verdict's `BoundTool` using the same
Policy. A selector also runs the legitimate owned-order path.

The workbench configures process-local evidence and approval stores so the lab needs no production
infrastructure. Those adapters are intentionally unsuitable for production, Octane, or queues.

## Configuration

Verdict's service provider and `Verdict` facade are registered automatically through Laravel's
package auto-discovery; no manual registration is required.

Verdict ships a `config/verdict.php` file. Publish it to customize the runtime adapters:

```bash
php artisan vendor:publish --tag=verdict-config
```

Verified confirmations use a database receipt store by default. Publish and run its migration
before enabling `requiresConfirmation(...)`:

```bash
php artisan vendor:publish --tag=verdict-migrations
php artisan migrate
```

- `approvals.store` — the `ApprovalReceiptStore` implementation. The default database store uses
  atomic row-locked transitions. The in-memory implementation is only for deterministic tests.
- `approvals.connection` and `approvals.table` — the database location for receipt state.
- `approvals.ttl_seconds` — the default receipt lifetime; a capability may select a shorter or
  longer lifetime explicitly.
- `evidence.recorder` — the `EvidenceRecorder` implementation used to record decisions. Defaults to
  `NullEvidenceRecorder`, which discards evidence, because silently choosing a storage destination
  or retention policy would be unsafe. `InMemoryEvidenceRecorder` is available for tests and local
  development; it is process-local and unbounded, and unsuitable for Octane, queues, or production.
- `ai.denied_message` — the message returned to the model when a proposal is not executed. Internal
  denial reasons are recorded in evidence but are never included in this message.

The receipt table intentionally retains terminal rows so an old tool-call cannot silently become a
new approval after deletion. A bounded pruning or tombstone policy has not been implemented yet;
do not delete terminal receipts while the corresponding Laravel AI conversation can still be
resumed or replayed.

## Roadmap

This roadmap is directional and may change as the integration is prototyped.

| Phase | Scope | Status |
|---|---|---|
| Design | Threat model, vocabulary, package boundary, demo design | Documented; ongoing |
| Runtime foundation | Capability registry, bound and guarded tools, staged decisions, Laravel Policy integration | First slice implemented |
| Identity and execution | Principal/tenant binding, confirmation state, expiry, idempotency | Confirmation receipt slice implemented; broader identity and idempotency planned |
| Context release | Source labels, field projection, PII scrubber contracts, destination policy | Planned |
| Evidence | Pluggable stores, redaction levels, security events, audit command | Planned |
| Evaluation | Deterministic attack cases, live-model suites, baselines, reports | Planned |
| Demo | Sandboxed eCommerce assistant and security trace | First deterministic workbench slice implemented; live-model path planned |
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

## Origins and security foundations

Verdict grows out of work on
[`fissible/llm-triage-eval`](https://github.com/fissible/llm-triage-eval) and a broader interest in
reproducible, evidence-backed evaluation of AI behavior.

The security model is informed by durable, vendor-neutral standards and research:

- [`OWASP Agentic AI - Threats and Mitigations`](https://genai.owasp.org/resource/agentic-ai-threats-and-mitigations/)
  provides a threat-model-based reference for agentic systems, including scoped tools and
  privileges, session isolation and retention, sandboxed execution, behavioral monitoring, rate
  limits, traceable logs, and post-incident review.
- [`OWASP LLM06:2025 Excessive Agency`](https://genai.owasp.org/llmrisk/llm062025-excessive-agency/)
  recommends minimizing tool functionality and permissions, executing actions in the user's
  security context, independently authorizing downstream actions through complete mediation, and
  requiring approval for high-impact operations.
- [`NIST AI 600-1: Generative Artificial Intelligence Profile`](https://doi.org/10.6028/NIST.AI.600-1)
  applies the AI Risk Management Framework to generative AI and covers threat modeling, retained
  test and evaluation history, provenance, red teaming, post-deployment monitoring, incident
  response, containment, and deactivation.
- The peer-reviewed
  [`AgentDojo`](https://papers.nips.cc/paper_files/paper/2024/hash/97091a5177d8dc64b1da8bf3e1f6fb54-Abstract-Datasets_and_Benchmarks_Track.html)
  work demonstrates stateful, adversarial evaluation of tool-using agents over untrusted data,
  with explicit utility and security outcomes and support for evolving attacks and defenses.

Related security work includes the
[`OWASP Top 10 for Agentic Applications`](https://genai.owasp.org/2025/12/09/owasp-top-10-for-agentic-applications-the-benchmark-for-agentic-security-in-the-age-of-autonomous-ai/).
These sources do not endorse Verdict. Verdict may map attack packs and evidence to their relevant
terminology, but such mappings would not constitute conformance or certification.

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
