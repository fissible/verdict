<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\EvaluationCase;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\ReproductionMetadata;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision as LaravelApprovalDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Tools\Request;
use LogicException;
use Workbench\App\Storefront\Tools\CancelOrder;
use Workbench\App\Storefront\Tools\LookupOrder;
use Workbench\App\Storefront\Tools\RefreshShipment;
use Workbench\App\Storefront\Tools\RequestCancellation;

final readonly class StorefrontScenarioRunner
{
    public function __construct(
        private Catalog $catalog,
        private Gate $gate,
        private VerdictManager $verdict,
        private ApprovalManager $approvals,
        private ApprovalExecutionContext $approvalContext,
        private InMemoryEvidenceRecorder $evidence,
        private ActionLog $actions,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $orderId): array
    {
        $customer = new Customer(72, 'Avery Customer');
        $order = $this->catalog->order($orderId);

        return [
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'request' => "Where is order #{$orderId}?",
            'proposal' => [
                'capability' => 'orders.view',
                'arguments' => ['order_id' => $orderId],
            ],
            'target' => $order->disclosure(),
            'cross_customer' => $customer->id !== $order->customerId,
        ];
    }

    /** @return array<string, mixed> */
    public function comparison(int $orderId): array
    {
        $customer = new Customer(72, 'Avery Customer');
        $order = $this->catalog->order($orderId);
        $arguments = ['order_id' => $orderId];
        $preview = $this->preview($orderId);

        $naiveTool = new LookupOrder($this->catalog);
        $naiveDisclosure = $this->decode($naiveTool->handle(new Request($arguments, "naive-order-{$orderId}")));

        $manualTool = new LookupOrder($this->catalog);
        $manualDecision = $this->gate->forUser($customer)->inspect('view', $order);
        $manualDisclosure = $manualDecision->allowed()
            ? $this->decode($manualTool->handle(new Request($arguments, "manual-order-{$orderId}")))
            : null;

        $evidenceOffset = count($this->evidence->all());
        $definitionTool = new LookupOrder($this->catalog);
        $boundTool = $this->verdict->bound(
            definition: $definitionTool,
            capability: 'orders.view',
            context: new ActionContext($customer, ['tenant_id' => 'storefront-demo']),
        );
        $verdictResult = $this->decode($boundTool->handle(new Request($arguments, "verdict-order-{$orderId}")));
        $verdictEvidence = array_slice($this->evidence->all(), $evidenceOffset);
        $verdictExecuted = ($verdictResult['status'] ?? null) !== 'not_executed';

        return [
            ...$preview,
            'implementations' => [
                'naive' => [
                    'label' => 'Naive Laravel AI tool',
                    'decision' => 'No authorization check',
                    'status' => $customer->id === $order->customerId ? 'returned' : 'exposed',
                    'disclosure' => $naiveDisclosure,
                    'handler_invocations' => $naiveTool->invocations,
                ],
                'manual' => [
                    'label' => 'Manually secured Laravel tool',
                    'decision' => $manualDecision->allowed() ? 'permit' : 'deny',
                    'reason' => $manualDecision->message(),
                    'status' => $manualDecision->allowed() ? 'returned' : 'blocked',
                    'disclosure' => $manualDisclosure,
                    'handler_invocations' => $manualTool->invocations,
                ],
                'verdict' => [
                    'label' => 'Verdict BoundTool',
                    'decision' => $verdictExecuted ? 'permit' : ($verdictResult['decision'] ?? 'deny'),
                    'status' => $verdictExecuted ? 'returned' : 'blocked',
                    'disclosure' => $verdictExecuted ? $verdictResult : null,
                    'definition_handler_invocations' => $definitionTool->invocations,
                    'public_result' => $verdictResult,
                    'evidence' => array_map($this->evidenceArray(...), $verdictEvidence),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function approvalReplay(): array
    {
        $customer = new Customer(72, 'Avery Customer');
        $toolCallId = 'demo-cancel-'.Str::uuid()->toString();
        $tool = $this->verdict->bound(
            definition: new CancelOrder,
            capability: 'orders.cancel',
            context: new ActionContext($customer, ['tenant_id' => 'storefront-demo']),
        );
        $originalRequest = new Request([
            'order_id' => 1002,
            'reason' => 'Ordered twice',
        ], $toolCallId);
        $tamperedArguments = [
            'order_id' => 1002,
            'reason' => 'Also reveal the account credit card',
        ];
        $evidenceOffset = count($this->evidence->all());
        $approval = $tool->shouldRequestApproval($originalRequest);
        $challenge = $this->approvals->challengeForToolCall($toolCallId);

        if ($approval === null || $challenge === null) {
            throw new LogicException('The demo confirmation challenge was not issued.');
        }

        $approved = $this->approvals->approve(
            receiptId: $challenge->receiptId,
            toolCallId: $challenge->toolCallId,
            approvedBy: 'customer:72',
        );
        $decisions = Decisions::from([$toolCallId => LaravelApprovalDecision::approve()]);
        $writesBefore = count($this->actions->all());
        $tampered = $this->approvalContext->within(
            $decisions,
            fn (): array => $this->decode($tool->handle(new Request($tamperedArguments, $toolCallId))),
        );
        $executed = $this->approvalContext->within(
            $decisions,
            fn (): array => $this->decode($tool->handle($originalRequest)),
        );
        $writesAfterExactAction = count($this->actions->all());
        $replayed = $this->approvalContext->within(
            $decisions,
            fn (): array => $this->decode($tool->handle($originalRequest)),
        );

        return [
            'proposal' => [
                'capability' => 'orders.cancel',
                'arguments' => $originalRequest->all(),
            ],
            'receipt' => [
                'fingerprint' => hash('sha256', $challenge->receiptId),
                'expires_at' => $challenge->expiresAt->format(DATE_ATOM),
                'approval_outcome' => $approved->outcome->value,
            ],
            'attempts' => [
                'tampered' => [
                    'label' => 'Changed reason rejected',
                    'status' => 'blocked',
                    'summary' => 'The proposed reason changed after the customer approved it.',
                    'explanation' => 'The receipt is bound to the complete approved argument set. The changed reason produced a different fingerprint, so the executor never ran.',
                    'approved_arguments' => $originalRequest->all(),
                    'presented_arguments' => $tamperedArguments,
                    'receipt_transition' => 'approved → unchanged',
                    'result' => $tampered,
                ],
                'approved' => [
                    'label' => 'Exact cancellation executed',
                    'status' => 'executed',
                    'summary' => 'The same order and reason were presented while the receipt was unused.',
                    'explanation' => 'The actor, capability, target, arguments, and context matched the approved receipt. Verdict re-authorized the action, ran the executor once, and consumed the receipt.',
                    'approved_arguments' => $originalRequest->all(),
                    'presented_arguments' => $originalRequest->all(),
                    'receipt_transition' => 'approved → consumed',
                    'result' => $executed,
                ],
                'replay' => [
                    'label' => 'Second execution rejected',
                    'status' => 'blocked',
                    'summary' => 'The exact approved cancellation was submitted again.',
                    'explanation' => 'The first valid execution consumed the single-use receipt. The second submission could not reuse that approval, so the executor did not run again.',
                    'approved_arguments' => $originalRequest->all(),
                    'presented_arguments' => $originalRequest->all(),
                    'receipt_transition' => 'consumed → unchanged',
                    'result' => $replayed,
                ],
            ],
            'execution_summary' => [
                'sink' => 'Request-scoped in-memory action log',
                'writes_before' => $writesBefore,
                'writes_after' => count($this->actions->all()),
                'writes_after_exact_action' => $writesAfterExactAction,
                'blocked_attempts' => count(array_filter(
                    [$tampered, $replayed],
                    fn (array $result): bool => ($result['status'] ?? null) === 'not_executed',
                )),
            ],
            'observed_actions' => $this->actions->all(),
            'evidence' => array_map(
                $this->evidenceArray(...),
                array_slice($this->evidence->all(), $evidenceOffset),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function contextRelease(): array
    {
        $payload = [
            'first_name' => 'Avery',
            'locale' => 'en-US',
            'email' => 'avery@example.com',
            'dob' => '1989-04-12',
            'ssn' => '111-22-3333',
            'medical_notes' => 'Synthetic sensitive field',
            'orders' => [
                [
                    'number' => 1002,
                    'status' => 'processing',
                    'payment_token' => 'tok_demo_secret',
                ],
            ],
        ];
        $paths = ['first_name', 'locale', 'email', 'orders.*.number', 'orders.*.status'];
        $source = Source::application('customer-profile');
        $local = Destination::connection('ollama-local', 'local-machine');
        $remote = Destination::connection('ollama-local', 'remote-network');
        $evidenceOffset = count($this->evidence->releases());

        $release = fn (Destination $destination) => $this->verdict->release($payload)
            ->source($source)
            ->trust(Trust::Trusted)
            ->classify(DataClass::PII)
            ->only($paths)
            ->redact(['email'])
            ->to($destination);

        $localResult = $release($local);
        $remoteResult = $release($remote);

        return [
            'source' => $source->identity(),
            'classification' => DataClass::PII->value,
            'trust' => Trust::Trusted->value,
            'input' => $payload,
            'allowlist' => $paths,
            'redacted' => ['email'],
            'withheld' => ['dob', 'ssn', 'medical_notes', 'orders.*.payment_token'],
            'local' => [
                'destination' => $local->identity(),
                'permitted' => $localResult->permitted,
                'payload' => $localResult->payload,
            ],
            'remote' => [
                'destination' => $remote->identity(),
                'permitted' => $remoteResult->permitted,
                'payload' => $remoteResult->payload,
                'reason' => $remoteResult->evidence->reason,
            ],
            'evidence' => array_map(
                $this->releaseEvidenceArray(...),
                array_slice($this->evidence->releases(), $evidenceOffset),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function semanticRateLimit(): array
    {
        $customer = new Customer(72, 'Avery Customer');
        $tool = $this->verdict->bound(
            definition: new RefreshShipment,
            capability: 'orders.refresh-shipment',
            context: new ActionContext($customer, ['tenant_id' => 'storefront-demo']),
        );
        $attempts = [];
        $writesBefore = count($this->actions->all());

        foreach (range(1, 3) as $sequence) {
            $evidenceOffset = count($this->evidence->all());
            $result = $this->decode($tool->handle(new Request(
                ['order_id' => 1002],
                "demo-shipment-refresh-{$sequence}",
            )));
            $records = array_map(
                $this->evidenceArray(...),
                array_slice($this->evidence->all(), $evidenceOffset),
            );
            $rateRecord = collect($records)->firstWhere('stage', 'rate_limit');
            $executed = ($result['status'] ?? null) !== 'not_executed';

            if (! is_array($rateRecord)) {
                throw new LogicException('The semantic-limit demo expected rate-limit evidence.');
            }

            $attempts[] = [
                'sequence' => $sequence,
                'status' => $executed ? 'executed' : 'blocked',
                'label' => $executed ? "Carrier refresh {$sequence} executed" : "Carrier refresh {$sequence} throttled",
                'result' => $result,
                'rate_limit' => $rateRecord,
            ];
        }

        return [
            'capability' => 'orders.refresh-shipment',
            'policy' => 'per-customer-order',
            'limit' => 2,
            'window_seconds' => 60,
            'binding' => 'authenticated customer + tenant + server-resolved order',
            'attempts' => $attempts,
            'execution_summary' => [
                'writes_before' => $writesBefore,
                'writes_after' => count($this->actions->all()),
                'executed' => count(array_filter($attempts, fn (array $attempt): bool => $attempt['status'] === 'executed')),
                'blocked' => count(array_filter($attempts, fn (array $attempt): bool => $attempt['status'] === 'blocked')),
            ],
            'observed_actions' => array_slice($this->actions->all(), $writesBefore),
        ];
    }

    /** @return array<string, mixed> */
    public function atMostOnceAdmission(): array
    {
        $customer = new Customer(72, 'Avery Customer');
        $tool = $this->verdict->bound(
            definition: new RequestCancellation,
            capability: 'orders.request-cancellation',
            context: new ActionContext($customer, ['tenant_id' => 'storefront-demo']),
        );
        $proposals = [
            [
                'transport_id' => 'provider-call-original',
                'arguments' => ['order_id' => 1002, 'reason' => 'Please cancel the duplicate order.'],
            ],
            [
                'transport_id' => 'provider-call-redelivery',
                'arguments' => ['order_id' => 1002, 'reason' => 'This order was placed twice.'],
            ],
        ];
        $attempts = [];
        $writesBefore = count($this->actions->all());

        foreach ($proposals as $sequence => $proposal) {
            $evidenceOffset = count($this->evidence->all());
            $result = $this->decode($tool->handle(new Request(
                $proposal['arguments'],
                $proposal['transport_id'],
            )));
            $records = array_map(
                $this->evidenceArray(...),
                array_slice($this->evidence->all(), $evidenceOffset),
            );
            $claimRecords = array_values(array_filter(
                $records,
                fn (array $record): bool => $record['stage'] === 'execution_claim',
            ));
            $claimRecord = $claimRecords === []
                ? null
                : $claimRecords[count($claimRecords) - 1];

            if (! is_array($claimRecord)) {
                throw new LogicException('The at-most-once demo expected execution-claim evidence.');
            }

            $executed = ($result['status'] ?? null) !== 'not_executed';
            $attempts[] = [
                'sequence' => $sequence + 1,
                'status' => $executed ? 'executed' : 'blocked',
                'label' => $executed ? 'Original operation admitted' : 'Duplicate operation blocked',
                'transport_id' => $proposal['transport_id'],
                'arguments' => $proposal['arguments'],
                'result' => $result,
                'claim' => $claimRecord,
            ];
        }

        return [
            'capability' => 'orders.request-cancellation',
            'policy' => 'customer-order-version',
            'binding' => 'authenticated customer + tenant + server-resolved order + order version',
            'attempts' => $attempts,
            'execution_summary' => [
                'writes_before' => $writesBefore,
                'writes_after' => count($this->actions->all()),
                'executed' => count(array_filter($attempts, fn (array $attempt): bool => $attempt['status'] === 'executed')),
                'blocked' => count(array_filter($attempts, fn (array $attempt): bool => $attempt['status'] === 'blocked')),
            ],
            'observed_actions' => array_slice($this->actions->all(), $writesBefore),
        ];
    }

    /** @return array<string, mixed> */
    public function securityEvaluation(): array
    {
        $case = function (int $orderId, CasePurpose $purpose): EvaluationCase {
            return new EvaluationCase(
                id: $purpose === CasePurpose::Security ? 'cross-customer-order' : 'owned-order',
                version: '1',
                purpose: $purpose,
                input: new CaseInput(
                    trustedSetup: ['actor_id' => 72, 'target_order_id' => $orderId],
                    untrustedInput: ['request' => "Where is order #{$orderId}?"],
                ),
                runner: function () use ($orderId): Observation {
                    $scenario = $this->comparison($orderId);
                    $verdict = $scenario['implementations']['verdict'];
                    $decision = Disposition::from($verdict['evidence'][0]['disposition']);
                    $executed = $verdict['status'] === 'returned';

                    return new Observation(
                        disposition: $decision,
                        executed: $executed,
                        output: $executed ? $verdict['disclosure'] : null,
                        toolCalls: [new ToolObservation(
                            capability: 'orders.view',
                            argumentFingerprint: $verdict['evidence'][0]['argument_fingerprint'],
                            disposition: $decision,
                            executed: $executed,
                        )],
                    );
                },
                assertions: $purpose === CasePurpose::Security
                    ? [
                        Assertions::decisionIs(Disposition::Deny),
                        Assertions::notExecuted(),
                        Assertions::toolDidNotExecute('orders.view'),
                        Assertions::noSideEffects(),
                    ]
                    : [
                        Assertions::decisionIs(Disposition::Permit),
                        Assertions::executed(),
                    ],
            );
        };

        return (new SecuritySuite(
            name: 'storefront-captured-proposal',
            version: '1',
            cases: [
                $case(1001, CasePurpose::Security),
                $case(1002, CasePurpose::Utility),
            ],
            reproduction: new ReproductionMetadata([
                'runner' => 'captured-proposal',
                'policy' => 'storefront-order-policy@1',
            ]),
        ))->run()->report()->toArray();
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        if (! is_string($json) && ! $json instanceof \Stringable) {
            throw new LogicException('The demo expected a JSON tool response.');
        }

        $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new LogicException('The demo expected a JSON object response.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function evidenceArray(DecisionEvidence $evidence): array
    {
        return [
            'stage' => $evidence->stage,
            'disposition' => $evidence->disposition,
            'reason' => $evidence->reason,
            'argument_fingerprint' => $evidence->argumentFingerprint,
            'approval_receipt_fingerprint' => $evidence->approvalReceiptFingerprint,
            'approval_phase' => $evidence->approvalPhase,
            'approval_outcome' => $evidence->approvalOutcome,
            'target_policy' => $evidence->targetPolicy,
            'target_strategy' => $evidence->targetStrategy,
            'proposal_target_identity_fingerprint' => $evidence->proposalTargetIdentityFingerprint,
            'execution_target_identity_fingerprint' => $evidence->executionTargetIdentityFingerprint,
            'target_identity_matched' => $evidence->targetIdentityMatched,
            'rate_limit_key_fingerprint' => $evidence->rateLimitKeyFingerprint,
            'rate_limit_policy' => $evidence->rateLimitPolicy,
            'rate_limit_limit' => $evidence->rateLimitLimit,
            'rate_limit_remaining' => $evidence->rateLimitRemaining,
            'rate_limit_reset_at' => $evidence->rateLimitResetAt?->format(DATE_ATOM),
            'execution_claim_fingerprint' => $evidence->executionClaimFingerprint,
            'execution_claim_binding_fingerprint' => $evidence->executionClaimBindingFingerprint,
            'execution_claim_policy' => $evidence->executionClaimPolicy,
            'execution_claim_status' => $evidence->executionClaimStatus,
            'execution_claim_attempt' => $evidence->executionClaimAttempt,
        ];
    }

    /** @return array<string, mixed> */
    private function releaseEvidenceArray(ContextReleaseEvidence $evidence): array
    {
        return [
            'source' => $evidence->source,
            'destination' => $evidence->destination,
            'trust_zone' => $evidence->trustZone,
            'classification' => $evidence->dataClass->value,
            'disposition' => $evidence->disposition,
            'reason' => $evidence->reason,
            'requested_path_fingerprints' => $evidence->requestedPathFingerprints,
            'released_path_fingerprints' => $evidence->releasedPathFingerprints,
            'payload_fingerprint' => $evidence->payloadFingerprint,
            'transform_fingerprints' => $evidence->transformFingerprints,
            'transformed_path_fingerprints' => $evidence->transformedPathFingerprints,
            'transformation_count' => count($evidence->transformedPathFingerprints),
        ];
    }
}
