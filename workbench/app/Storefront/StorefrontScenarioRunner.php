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
        $paths = ['first_name', 'locale', 'orders.*.number', 'orders.*.status'];
        $source = Source::application('customer-profile');
        $local = Destination::connection('ollama-local', 'local-machine');
        $remote = Destination::connection('ollama-local', 'remote-network');
        $evidenceOffset = count($this->evidence->releases());

        $release = fn (Destination $destination) => $this->verdict->release($payload)
            ->source($source)
            ->trust(Trust::Trusted)
            ->classify(DataClass::PII)
            ->only($paths)
            ->to($destination);

        $localResult = $release($local);
        $remoteResult = $release($remote);

        return [
            'source' => $source->identity(),
            'classification' => DataClass::PII->value,
            'trust' => Trust::Trusted->value,
            'input' => $payload,
            'allowlist' => $paths,
            'withheld' => ['email', 'dob', 'ssn', 'medical_notes', 'orders.*.payment_token'],
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
        ];
    }
}
