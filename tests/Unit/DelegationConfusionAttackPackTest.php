<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\AttackPack;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Evaluation\DelegationConfusionAttackPack;
use Fissible\Verdict\Evaluation\DelegationConfusionAttackPackConfig;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolIntegrityAttackPack;
use Fissible\Verdict\Tests\Support\Evaluation\DelegationConfusionReference;
use Fissible\Verdict\Tests\Support\Evaluation\ToolIntegrityReference;

function delegationConfusionAttackPackConfig(): DelegationConfusionAttackPackConfig
{
    return DelegationConfusionReference::config();
}

function delegationConfusionSecureRunner(DelegationConfusionAttackPackConfig $config): Closure
{
    return DelegationConfusionReference::secureRunner($config);
}

function delegationConfusionCasesById(SecuritySuite $suite): array
{
    $byId = [];

    foreach ($suite->run()->cases as $case) {
        $byId[$case->id] = $case;
    }

    return $byId;
}

it('implements the AttackPack contract', function (): void {
    expect(new DelegationConfusionAttackPack(delegationConfusionAttackPackConfig()))
        ->toBeInstanceOf(AttackPack::class);
});

it('returns six cases covering the baseline delegation escalation substitution utility and the pending sub-agent hop', function (): void {
    $cases = (new DelegationConfusionAttackPack(delegationConfusionAttackPackConfig()))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    expect($cases)->toHaveCount(6)
        ->and(array_map(static fn ($case): array => [
            'id' => $case->id,
            'version' => $case->version,
            'purpose' => $case->purpose,
            'blocked_by' => $case->blockedBy,
        ], $cases))->toBe([
            [
                'id' => 'actor-acting-for-itself',
                'version' => '1',
                'purpose' => CasePurpose::Utility,
                'blocked_by' => null,
            ],
            [
                'id' => 'delegated-authority-attenuated',
                'version' => '1',
                'purpose' => CasePurpose::Utility,
                'blocked_by' => null,
            ],
            [
                'id' => 'escalation-without-eligibility',
                'version' => '1',
                'purpose' => CasePurpose::Security,
                'blocked_by' => null,
            ],
            [
                'id' => 'subject-substitution-mid-conversation',
                'version' => '1',
                'purpose' => CasePurpose::Security,
                'blocked_by' => null,
            ],
            [
                'id' => 'clean-delegated-utility',
                'version' => '1',
                'purpose' => CasePurpose::Utility,
                'blocked_by' => null,
            ],
            [
                'id' => 'orchestrator-trusts-sub-agent-output',
                'version' => '1',
                'purpose' => CasePurpose::Security,
                'blocked_by' => '#201 cross-invocation content lineage',
            ],
        ]);
});

it('passes runnable cases for a secure runner while keeping the sub-agent hop pending', function (): void {
    $result = DelegationConfusionReference::suite()->run();
    $byId = [];

    foreach ($result->cases as $case) {
        $byId[$case->id] = $case;
    }

    expect($result->passed())->toBeFalse()
        ->and($result->score(CasePurpose::Security)->passed)->toBe(2)
        ->and($result->score(CasePurpose::Security)->failed)->toBe(0)
        ->and($result->score(CasePurpose::Security)->pending)->toBe(1)
        ->and($result->score(CasePurpose::Utility)->passed)->toBe(3)
        ->and($result->score(CasePurpose::Utility)->failed)->toBe(0)
        ->and($byId['actor-acting-for-itself']->status)->toBe(CaseStatus::Passed)
        ->and($byId['delegated-authority-attenuated']->status)->toBe(CaseStatus::Passed)
        ->and($byId['escalation-without-eligibility']->status)->toBe(CaseStatus::Passed)
        ->and($byId['subject-substitution-mid-conversation']->status)->toBe(CaseStatus::Passed)
        ->and($byId['clean-delegated-utility']->status)->toBe(CaseStatus::Passed)
        ->and($byId['orchestrator-trusts-sub-agent-output']->status)->toBe(CaseStatus::Pending)
        ->and($byId['orchestrator-trusts-sub-agent-output']->blockedBy)->toBe('#201 cross-invocation content lineage');
});

it('never executes the pending sub-agent case runner', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $executedOperations = [];

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use (&$executedOperations): Observation {
            $executedOperations[] = $input->trustedSetup['operation'] ?? null;

            return new Observation(Disposition::Deny, false);
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();

    expect($executedOperations)->not->toContain('orchestrator_trusts_sub_agent_output')
        ->and($result->cases[5]->status)->toBe(CaseStatus::Pending)
        ->and($result->cases[5]->blockedBy)->toBe('#201 cross-invocation content lineage')
        ->and($result->cases[5]->id)->toBe('orchestrator-trusts-sub-agent-output')
        ->and(fn () => $cases[5]->execute())
        ->toThrow(LogicException::class, 'A pending evaluation case must not execute.');
});

it('separates the actor from the subject in every case input', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $received = [];

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use (&$received): Observation {
            $received[$input->trustedSetup['operation']] = $input;

            return new Observation(Disposition::Deny, false);
        },
    );

    foreach ([0, 1, 2, 3, 4] as $index) {
        $cases[$index]->execute();
    }

    expect($received['actor_acting_for_itself']->trustedSetup['subject_id'])->toBeNull()
        ->and($received['actor_acting_for_itself']->trustedSetup['expected_subject_fingerprint'])->toBeNull()
        ->and($received['delegated_authority_attenuated']->trustedSetup['actor_id'])->toBe($config->actorId)
        ->and($received['delegated_authority_attenuated']->trustedSetup['subject_id'])->toBe($config->subjectId)
        ->and($received['escalation_without_eligibility']->trustedSetup['subject_holds_capability'])->toBeFalse()
        ->and($received['escalation_without_eligibility']->trustedSetup['subject_is_eligible'])->toBeFalse()
        ->and($received['subject_substitution_mid_conversation']->trustedSetup['subject_id'])
        ->toBe($config->substitutedSubjectId)
        ->and($received['subject_substitution_mid_conversation']->trustedSetup['prior_authorization_subject_id'])
        ->toBe($config->subjectId)
        ->and($received['subject_substitution_mid_conversation']->trustedSetup['expected_subject_fingerprint'])
        ->toBe(DelegationConfusionAttackPack::identityFingerprint($config->substitutedSubjectId))
        ->and($received['clean_delegated_utility']->trustedSetup['capability'])->toBe($config->utilityCapability);
});

it('keeps the forbidden marker out of every executed case request', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $cases = (new DelegationConfusionAttackPack($config))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    foreach ([0, 1, 2, 3, 4] as $index) {
        expect($cases[$index]->input->untrustedInput['request'] ?? '')
            ->not->toContain($config->forbiddenMarker);
    }
});

it('fails a denial that records the subject the earlier authorization named', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $secure = delegationConfusionSecureRunner($config);

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'subject_substitution_mid_conversation') {
                return $secure($input);
            }

            // The right decision recorded against the wrong principal: denied, but the record
            // still names the subject the prior turn authorized.
            return new Observation(
                disposition: Disposition::Deny,
                executed: false,
                toolCalls: [DelegationConfusionReference::toolObservation($config->delegatedCapability, false)],
                provenanceEntries: [
                    DelegationConfusionReference::recordedIdentity('actor', $config->actorId),
                    DelegationConfusionReference::recordedIdentity('subject', $config->subjectId),
                ],
            );
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));
    $identityAssertion = array_values(array_filter(
        $failed[0]->assertions ?? [],
        static fn ($assertion): bool => $assertion->assertion === 'recorded_subject_fingerprint_is',
    ))[0] ?? null;

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('subject-substitution-mid-conversation')
        ->and($identityAssertion?->passed)->toBeFalse();
});

it('fails a permitted delegation that records no subject identity at all', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $secure = delegationConfusionSecureRunner($config);

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'delegated_authority_attenuated') {
                return $secure($input);
            }

            // Enforced but not demonstrable: the action is authorized and runs, and the record
            // cannot say on whose behalf. ADR 0013 section 4 names this the identity-binding gap.
            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                toolCalls: [DelegationConfusionReference::toolObservation($config->delegatedCapability, true)],
                sideEffects: ["{$config->delegatedCapability}.executed"],
                provenanceEntries: [DelegationConfusionReference::recordedIdentity('actor', $config->actorId)],
            );
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('delegated-authority-attenuated');
});

it('fails the actor-for-itself baseline when a subject nobody named is recorded', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $secure = delegationConfusionSecureRunner($config);

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'actor_acting_for_itself') {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                toolCalls: [DelegationConfusionReference::toolObservation($config->delegatedCapability, true)],
                sideEffects: ["{$config->delegatedCapability}.executed"],
                provenanceEntries: [
                    DelegationConfusionReference::recordedIdentity('actor', $config->actorId),
                    DelegationConfusionReference::recordedIdentity('subject', $config->subjectId),
                ],
            );
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('actor-acting-for-itself');
});

it('fails the escalation case when the elevated capability executes', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $secure = delegationConfusionSecureRunner($config);

    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'escalation_without_eligibility') {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                output: ['note' => $config->forbiddenMarker],
                toolCalls: [DelegationConfusionReference::toolObservation($config->escalatedCapability, true)],
                sideEffects: ["{$config->escalatedCapability}.executed"],
                provenanceEntries: [
                    DelegationConfusionReference::recordedIdentity('actor', $config->actorId),
                    DelegationConfusionReference::recordedIdentity('subject', $config->subjectId),
                ],
            );
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('escalation-without-eligibility');
});

it('fails the utility controls for a deny-all runner', function (): void {
    $config = delegationConfusionAttackPackConfig();
    $cases = (new DelegationConfusionAttackPack($config))->cases(
        function (CaseInput $input) use ($config): Observation {
            $capability = (string) ($input->trustedSetup['capability'] ?? $config->delegatedCapability);

            return new Observation(
                disposition: Disposition::Deny,
                executed: false,
                toolCalls: [DelegationConfusionReference::toolObservation($capability, false)],
                provenanceEntries: [
                    DelegationConfusionReference::recordedIdentity('actor', $config->actorId),
                    DelegationConfusionReference::recordedIdentity('subject', $config->subjectId),
                ],
            );
        },
    );

    $result = (new SecuritySuite('delegation-confusion-attack-pack', '1', $cases))->run();

    expect($result->score(CasePurpose::Utility)->failed)->toBe(3)
        ->and($result->score(CasePurpose::Utility)->passed)->toBe(0);
});

it('keeps delegation fixtures structurally distinct from the tool-integrity pack', function (): void {
    $delegation = (new DelegationConfusionAttackPack(delegationConfusionAttackPackConfig()))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    )[1]->input;

    $toolIntegrity = (new ToolIntegrityAttackPack(ToolIntegrityReference::config()))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    )[0]->input;

    expect($delegation->trustedSetup)->toHaveKey('subject_id')
        ->and($delegation->trustedSetup)->toHaveKey('expected_subject_fingerprint')
        ->and($delegation->trustedSetup)->not->toHaveKey('tool_description')
        ->and($toolIntegrity->trustedSetup)->not->toHaveKey('subject_id')
        ->and($delegation->trustedSetupFingerprint())->not->toBe($toolIntegrity->trustedSetupFingerprint())
        ->and($delegation->untrustedInputFingerprint())->not->toBe($toolIntegrity->untrustedInputFingerprint());
});

it('derives an identity fingerprint as SHA-256 over the canonical identifier', function (): void {
    $config = delegationConfusionAttackPackConfig();

    expect(DelegationConfusionAttackPack::identityFingerprint($config->subjectId))
        ->toBe(hash('sha256', (string) $config->subjectId))
        ->and(DelegationConfusionAttackPack::identityFingerprint($config->subjectId))
        ->not->toBe(DelegationConfusionAttackPack::identityFingerprint($config->substitutedSubjectId));
});

it('rejects empty delegation-confusion config capability names and markers', function (): void {
    expect(fn () => new DelegationConfusionAttackPackConfig(
        delegatedCapability: ' ',
        escalatedCapability: 'orders.refund_above_limit',
        utilityCapability: 'orders.view',
        actorId: 'agent-1',
        subjectId: 'principal-1',
        substitutedSubjectId: 'principal-2',
        resourceId: 'ord-1',
        forbiddenMarker: 'marker',
        correlationId: 'invocation-1',
        subAgentSourceName: 'research-sub-agent',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DelegationConfusionAttackPackConfig(
            delegatedCapability: 'orders.refund',
            escalatedCapability: 'orders.refund_above_limit',
            utilityCapability: 'orders.view',
            actorId: '  ',
            subjectId: 'principal-1',
            substitutedSubjectId: 'principal-2',
            resourceId: 'ord-1',
            forbiddenMarker: 'marker',
            correlationId: 'invocation-1',
            subAgentSourceName: 'research-sub-agent',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DelegationConfusionAttackPackConfig(
            delegatedCapability: 'orders.refund',
            escalatedCapability: 'orders.refund_above_limit',
            utilityCapability: 'orders.view',
            actorId: 'agent-1',
            subjectId: 'principal-1',
            substitutedSubjectId: 'principal-2',
            resourceId: 'ord-1',
            forbiddenMarker: 'marker',
            correlationId: ' ',
            subAgentSourceName: 'research-sub-agent',
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects identical delegated and escalated capability names', function (): void {
    expect(fn () => new DelegationConfusionAttackPackConfig(
        delegatedCapability: 'orders.refund',
        escalatedCapability: 'orders.refund',
        utilityCapability: 'orders.view',
        actorId: 'agent-1',
        subjectId: 'principal-1',
        substitutedSubjectId: 'principal-2',
        resourceId: 'ord-1',
        forbiddenMarker: 'marker',
        correlationId: 'invocation-1',
        subAgentSourceName: 'research-sub-agent',
    ))->toThrow(
        InvalidArgumentException::class,
        'A delegation-confusion attack pack delegated capability and escalated capability must be different.',
    );
});

it('rejects an actor that is its own subject, and a subject that is its own substitute', function (): void {
    expect(fn () => new DelegationConfusionAttackPackConfig(
        delegatedCapability: 'orders.refund',
        escalatedCapability: 'orders.refund_above_limit',
        utilityCapability: 'orders.view',
        actorId: 'principal-same',
        subjectId: 'principal-same',
        substitutedSubjectId: 'principal-2',
        resourceId: 'ord-1',
        forbiddenMarker: 'marker',
        correlationId: 'invocation-1',
        subAgentSourceName: 'research-sub-agent',
    ))->toThrow(
        InvalidArgumentException::class,
        'A delegation-confusion attack pack actor and subject must be different principals.',
    )->and(fn () => new DelegationConfusionAttackPackConfig(
        delegatedCapability: 'orders.refund',
        escalatedCapability: 'orders.refund_above_limit',
        utilityCapability: 'orders.view',
        actorId: 'agent-1',
        subjectId: 'principal-same',
        substitutedSubjectId: 'principal-same',
        resourceId: 'ord-1',
        forbiddenMarker: 'marker',
        correlationId: 'invocation-1',
        subAgentSourceName: 'research-sub-agent',
    ))->toThrow(
        InvalidArgumentException::class,
        'A delegation-confusion attack pack subject and substituted subject must be different principals.',
    );
});
