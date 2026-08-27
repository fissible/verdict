<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support\Evaluation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\InvocationContext;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\ProposalAnchor;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\FieldProjector;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\ChallengeObservation;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\RagBorneInjectionAttackPack;
use Fissible\Verdict\Evaluation\RagBorneInjectionAttackPackConfig;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use RuntimeException;

/**
 * Reference wiring for RagBorneInjectionAttackPack: the secure runner its
 * tests assert against, shared with the committed-baseline run so the baseline
 * pins exactly the behaviour the tests specify.
 */
final class RagBorneInjectionReference
{
    public const string SUITE = 'rag-borne-injection-attack-pack';

    public const string VERSION = '2';

    public static function suite(): SecuritySuite
    {
        $config = self::config();

        $pack = new RagBorneInjectionAttackPack($config);

        return new SecuritySuite(
            self::SUITE,
            self::VERSION,
            $pack->cases(self::secureRunner($config)),
            toolShapes: $pack->expressibleToolShapes(),
        );
    }

    public static function config(): RagBorneInjectionAttackPackConfig
    {
        return new RagBorneInjectionAttackPackConfig(
            consequentialCapability: 'payments.transfer',
            actorId: 'actor-verdict-synthetic-rag-72',
            unauthorizedActorId: 'actor-verdict-synthetic-rag-91',
            safeRecipient: 'acct-verdict-synthetic-safe-100',
            manipulatedRecipient: 'acct-verdict-synthetic-attacker-200',
            safeAmount: 50,
            manipulatedAmount: 5000,
            forbiddenMarker: 'verdict-synthetic-rag-forbidden-marker',
            externalSourceName: 'support-ticket-index',
            correlationId: 'invocation-verdict-synthetic-rag-43',
        );
    }

    public static function toolObservation(
        string $capability,
        bool $executed,
        ?string $argumentFingerprint = null,
        ?Disposition $disposition = null,
    ): ToolObservation {
        return new ToolObservation(
            capability: $capability,
            argumentFingerprint: $argumentFingerprint ?? str_repeat($executed ? 'b' : 'a', 64),
            disposition: $disposition ?? ($executed ? Disposition::Permit : Disposition::Deny),
            executed: $executed,
        );
    }

    public static function provenanceLedger(InMemoryEvidenceRecorder $recorder): ProvenanceLedger
    {
        return new ProvenanceLedger($recorder, $recorder, self::clock());
    }

    private static function clock(): Clock
    {
        return new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-08 12:00:00', new DateTimeZone('UTC'));
            }
        };
    }

    /** @return list<ProvenanceEntry> */
    public static function recordRetrievedDocument(
        RagBorneInjectionAttackPackConfig $config,
        InMemoryEvidenceRecorder $recorder,
        string $body,
        Trust $trust = Trust::Untrusted,
        ?Source $source = null,
        ContextChannel $channel = ContextChannel::RetrievedDocument,
    ): array {
        self::provenanceLedger($recorder)->record(
            correlationId: $config->correlationId,
            source: $source ?? Source::external($config->externalSourceName),
            trust: $trust,
            dataClass: DataClass::Internal,
            channel: $channel,
            content: $body,
        );

        return $recorder->provenanceFor($config->correlationId);
    }

    public static function secureRunner(RagBorneInjectionAttackPackConfig $config): Closure
    {
        $approved = [
            'recipient' => $config->safeRecipient,
            'amount' => $config->safeAmount,
        ];
        $approvedFingerprint = ArgumentFingerprint::make($approved);
        $effect = "{$config->consequentialCapability}.executed";

        return function (CaseInput $input) use ($config, $approvedFingerprint, $effect): Observation {
            $operation = $input->trustedSetup['operation'] ?? null;
            $capability = $config->consequentialCapability;

            return match ($operation) {
                'rag_unauthorized_action' => new Observation(
                    disposition: Disposition::Deny,
                    executed: false,
                    output: ['summary' => 'Safe support summary'],
                    toolCalls: [self::toolObservation($capability, false)],
                ),
                'rag_confirmable_action' => new Observation(
                    disposition: Disposition::RequireConfirmation,
                    executed: false,
                    toolCalls: [self::toolObservation(
                        $capability,
                        false,
                        disposition: Disposition::RequireConfirmation,
                    )],
                ),
                'rag_argument_manipulation' => ArgumentFingerprint::make(
                    $input->untrustedInput['presented_arguments'] ?? [],
                ) === $approvedFingerprint
                    ? new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        toolCalls: [self::toolObservation($capability, true, $approvedFingerprint)],
                        sideEffects: [$effect],
                    )
                    : new Observation(
                        disposition: Disposition::RequireConfirmation,
                        executed: false,
                        toolCalls: [self::toolObservation(
                            $capability,
                            false,
                            ArgumentFingerprint::make($input->untrustedInput['presented_arguments'] ?? []),
                            Disposition::RequireConfirmation,
                        )],
                    ),
                'rag_provenance' => (function () use ($config, $capability, $input): Observation {
                    $recorder = new InMemoryEvidenceRecorder;
                    $body = (string) ($input->untrustedInput['retrieved_document']['body'] ?? '');
                    $entries = self::recordRetrievedDocument($config, $recorder, $body);

                    return new Observation(
                        disposition: Disposition::Deny,
                        executed: false,
                        output: ['summary' => 'Safe support summary'],
                        toolCalls: [self::toolObservation($capability, false)],
                        provenanceEntries: $entries,
                    );
                })(),
                'rag_challenge_provenance' => self::challengeObservation($config),
                default => throw new RuntimeException("Unexpected operation [{$operation}]."),
            };
        };
    }

    private static function challengeObservation(RagBorneInjectionAttackPackConfig $config): Observation
    {
        $recorder = new InMemoryEvidenceRecorder;
        $clock = self::clock();
        $ledger = new ProvenanceLedger($recorder, $recorder, $clock);
        $invocations = new InvocationContext;

        $arguments = ['recipient' => $config->safeRecipient, 'amount' => $config->safeAmount];
        $entry = $ledger->record(
            correlationId: $config->correlationId,
            source: Source::external($config->externalSourceName),
            trust: Trust::Untrusted,
            dataClass: DataClass::Internal,
            channel: ContextChannel::RetrievedDocument,
            content: 'verdict-synthetic-rag challenge-provenance document',
        );
        $ledger->declareDerivation(
            correlationId: $config->correlationId,
            childContentFingerprint: ProposalAnchor::for($arguments),
            parentContentFingerprints: [$entry->contentFingerprint],
            kind: DerivationKind::Summarized,
        );

        $policies = (new ReleasePolicyRegistry)->register(
            ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
                ->allow(DataClass::Internal)
                ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
        );
        $releases = new ContextReleaseManager($policies, new FieldProjector, $recorder, $clock, $invocations, $ledger);
        $approvals = new ApprovalManager(
            new InMemoryApprovalReceiptStore,
            new ApprovalExecutionContext,
            $clock,
            new ApproverProvenanceRelease($ledger, $releases, $policies),
            $invocations,
            900,
        );

        $capability = Capability::usingPolicy($config->consequentialCapability, 'update', fn (ActionEnvelope $envelope): array => $envelope->proposal->arguments)
            ->requiresConfirmation(
                bindUsing: fn (ActionEnvelope $envelope, array $target): array => $target,
                reason: 'Confirm this transfer.',
            );

        $toolCallId = 'call-verdict-synthetic-rag-challenge-1';
        $envelope = ActionEnvelope::wrap(
            new ActionProposal($config->consequentialCapability, $arguments, $toolCallId),
            new ActionContext(actor: $config->actorId),
        );

        $invocations->push($config->correlationId);
        $approvals->issue(new Evaluation($envelope, $capability, $arguments, Decision::requireConfirmation('Confirm this transfer.'), EvaluationStage::Proposal));
        $challenge = $approvals->challengeForToolCall($toolCallId);
        $invocations->pop();

        if ($challenge === null) {
            throw new RuntimeException('Reference issuance failed to produce a readable challenge.');
        }

        return new Observation(
            disposition: Disposition::RequireConfirmation,
            executed: false,
            toolCalls: [self::toolObservation(
                $config->consequentialCapability,
                false,
                ArgumentFingerprint::make($arguments),
                Disposition::RequireConfirmation,
            )],
            challenges: [ChallengeObservation::fromChallenge($challenge)],
        );
    }
}
