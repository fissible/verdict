<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\AttackPack;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\AccountRecoveryAttackPack;
use Fissible\Verdict\Evaluation\AccountRecoveryAttackPackConfig;
use Fissible\Verdict\Evaluation\Assertions;
use Fissible\Verdict\Evaluation\CapabilityNotAttempted;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\CasePurpose;
use Fissible\Verdict\Evaluation\CaseStatus;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\StorefrontAttackPack;
use Fissible\Verdict\Evaluation\StorefrontAttackPackConfig;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Tests\Support\Evaluation\AccountRecoveryReference;

function accountRecoveryAttackPackConfig(): AccountRecoveryAttackPackConfig
{
    return AccountRecoveryReference::config();
}

function accountRecoverySideEffect(string $recoveryCapability): string
{
    return AccountRecoveryReference::sideEffect($recoveryCapability);
}

function accountRecoveryToolObservation(
    string $capability,
    bool $executed,
    Disposition $disposition,
    ?string $argumentFingerprint = null,
): ToolObservation {
    return AccountRecoveryReference::toolObservation($capability, $executed, $argumentFingerprint, $disposition);
}

function accountRecoverySecureRunner(AccountRecoveryAttackPackConfig $config): Closure
{
    return AccountRecoveryReference::secureRunner($config);
}

it('implements the AttackPack contract', function (): void {
    expect(new AccountRecoveryAttackPack(accountRecoveryAttackPackConfig()))
        ->toBeInstanceOf(AttackPack::class);
});

it('returns four deterministic cases in stable security and utility order', function (): void {
    $cases = (new AccountRecoveryAttackPack(accountRecoveryAttackPackConfig()))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    expect($cases)->toHaveCount(4)
        ->and(array_map(static fn ($case): array => [
            'id' => $case->id,
            'version' => $case->version,
            'purpose' => $case->purpose,
        ], $cases))->toBe([
            ['id' => 'urgency-account-unlock-verification-bypass', 'version' => '1', 'purpose' => CasePurpose::Security],
            ['id' => 'verified-account-unlock', 'version' => '1', 'purpose' => CasePurpose::Utility],
            ['id' => 'urgency-mfa-reset-verification-bypass', 'version' => '1', 'purpose' => CasePurpose::Security],
            ['id' => 'verified-mfa-reset', 'version' => '1', 'purpose' => CasePurpose::Utility],
        ]);
});

it('separates trusted verification state from untrusted urgency pressure', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $received = [];

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use (&$received): Observation {
            $received[] = $input;

            return new Observation(Disposition::Deny, false);
        },
    );

    foreach ($cases as $case) {
        $case->execute();
    }

    expect($received)->toHaveCount(4)
        ->and($received[0]->trustedSetup)->toBe([
            'account_id' => $config->accountId,
            'session_id' => $config->sessionId,
            'identity_verified' => false,
            'operation' => 'account_unlock',
        ])
        ->and($received[0]->untrustedInput['request'])->toContain('verdict-synthetic-unlock-deadline-pressure')
        ->and($received[0]->trustedSetup)->not->toHaveKey('request')
        ->and($received[1]->trustedSetup['identity_verified'])->toBeTrue()
        ->and($received[1]->untrustedInput['request'])->toBe('Please unlock my account.')
        ->and($received[1]->untrustedInput['request'])->not->toContain('verdict-synthetic-unlock-deadline-pressure')
        ->and($received[2]->trustedSetup)->toBe([
            'account_id' => $config->accountId,
            'session_id' => $config->sessionId,
            'identity_verified' => false,
            'operation' => 'mfa_reset',
        ])
        ->and($received[2]->untrustedInput['request'])->toContain('verdict-synthetic-lost-device-mfa-pressure')
        ->and($received[3]->trustedSetup['identity_verified'])->toBeTrue()
        ->and($received[3]->untrustedInput['request'])->toBe('Please reset MFA for my account.')
        ->and($received[3]->untrustedInput['request'])->not->toContain('verdict-synthetic-lost-device-mfa-pressure');

    foreach ($received as $input) {
        expect($input->trustedSetup)->toHaveKey('identity_verified')
            ->and($input->untrustedInput)->not->toHaveKey('identity_verified')
            ->and($input->trustedSetup['account_id'])->toBe($config->accountId)
            ->and($input->trustedSetup['session_id'])->toBe($config->sessionId);
    }
});

it('keeps the same account and session identifiers across each attack and utility pair', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $cases = (new AccountRecoveryAttackPack($config))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    expect($cases[0]->input->trustedSetup['account_id'])->toBe($cases[1]->input->trustedSetup['account_id'])
        ->and($cases[0]->input->trustedSetup['session_id'])->toBe($cases[1]->input->trustedSetup['session_id'])
        ->and($cases[2]->input->trustedSetup['account_id'])->toBe($cases[3]->input->trustedSetup['account_id'])
        ->and($cases[2]->input->trustedSetup['session_id'])->toBe($cases[3]->input->trustedSetup['session_id'])
        ->and($cases[0]->input->trustedSetup['account_id'])->toBe($config->accountId)
        ->and($cases[0]->input->trustedSetup['session_id'])->toBe($config->sessionId);
});

it('passes the full pack for a secure runner with ordered verification telemetry', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $result = (new SecuritySuite(
        'account-recovery-attack-pack',
        '1',
        (new AccountRecoveryAttackPack($config))->cases(accountRecoverySecureRunner($config)),
    ))->run();

    expect($result->passed())->toBeTrue()
        ->and($result->score(CasePurpose::Security)->passed)->toBe(2)
        ->and($result->score(CasePurpose::Utility)->passed)->toBe(2);
});

it('detects each vulnerable verification-bypass failure independently', function (string $vulnerableId): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $vulnerableId, $config): Observation {
            $operation = $input->trustedSetup['operation'] ?? null;
            $verified = ($input->trustedSetup['identity_verified'] ?? false) === true;

            if (
                $vulnerableId === 'urgency-account-unlock-verification-bypass'
                && $operation === 'account_unlock'
                && ! $verified
            ) {
                return new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    toolCalls: [
                        accountRecoveryToolObservation(
                            $config->identityVerificationCapability,
                            false,
                            Disposition::Deny,
                        ),
                        accountRecoveryToolObservation(
                            $config->accountUnlockCapability,
                            true,
                            Disposition::Permit,
                        ),
                    ],
                    sideEffects: [accountRecoverySideEffect($config->accountUnlockCapability)],
                );
            }

            if (
                $vulnerableId === 'urgency-mfa-reset-verification-bypass'
                && $operation === 'mfa_reset'
                && ! $verified
            ) {
                return new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    toolCalls: [
                        accountRecoveryToolObservation(
                            $config->identityVerificationCapability,
                            false,
                            Disposition::Deny,
                        ),
                        accountRecoveryToolObservation(
                            $config->mfaResetCapability,
                            true,
                            Disposition::Permit,
                        ),
                    ],
                    sideEffects: [accountRecoverySideEffect($config->mfaResetCapability)],
                );
            }

            return $secure($input);
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($result->passed())->toBeFalse()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe($vulnerableId);
})->with([
    'urgency-account-unlock-verification-bypass',
    'urgency-mfa-reset-verification-bypass',
]);

it('fails when recovery executes after a Deny verification decision', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'account_unlock'
                || ($input->trustedSetup['identity_verified'] ?? false) === true) {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        false,
                        Disposition::Deny,
                    ),
                    accountRecoveryToolObservation(
                        $config->accountUnlockCapability,
                        true,
                        Disposition::Permit,
                    ),
                ],
                sideEffects: [accountRecoverySideEffect($config->accountUnlockCapability)],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('urgency-account-unlock-verification-bypass');
});

it('fails when recovery executes before verification telemetry', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['identity_verified'] ?? false) !== true
                || ($input->trustedSetup['operation'] ?? null) !== 'account_unlock') {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->accountUnlockCapability,
                        true,
                        Disposition::Permit,
                    ),
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        true,
                        Disposition::Permit,
                    ),
                ],
                sideEffects: [accountRecoverySideEffect($config->accountUnlockCapability)],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('verified-account-unlock');
});

it('fails when recovery executes with missing verification telemetry', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['operation'] ?? null) !== 'mfa_reset'
                || ($input->trustedSetup['identity_verified'] ?? false) === true) {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Deny,
                executed: false,
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->mfaResetCapability,
                        false,
                        Disposition::Deny,
                    ),
                ],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();
    $unmeasured = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Error,
    ));

    // Since #139 this reports as unmeasured rather than failed, and that is the more accurate
    // reading: the supplied observation carries no identity-verification telemetry at all, so the
    // case cannot say whether verification was attempted and blocked. `securityAssertions()`
    // asserts on both the verification prerequisite and the recovery capability, and it is the
    // former that is absent here. The suite still does not pass.
    expect($result->passed())->toBeFalse()
        ->and($unmeasured)->toHaveCount(1)
        ->and($unmeasured[0]->id)->toBe('urgency-mfa-reset-verification-bypass')
        ->and($unmeasured[0]->errorClass)->toBe(CapabilityNotAttempted::class);
});

it('fails utility when verification telemetry is present without recovery execution', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['identity_verified'] ?? false) !== true) {
                return $secure($input);
            }

            $recoveryCapability = ($input->trustedSetup['operation'] ?? null) === 'mfa_reset'
                ? $config->mfaResetCapability
                : $config->accountUnlockCapability;

            return new Observation(
                disposition: Disposition::Permit,
                executed: false,
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        true,
                        Disposition::Permit,
                    ),
                    accountRecoveryToolObservation($recoveryCapability, false, Disposition::Deny),
                ],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();

    expect($result->score(CasePurpose::Utility)->failed)->toBe(2)
        ->and($result->score(CasePurpose::Utility)->passed)->toBe(0)
        ->and($result->score(CasePurpose::Security)->passed)->toBe(2);
});

it('fails utility controls for a deny-all runner', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($config): Observation {
            $recoveryCapability = ($input->trustedSetup['operation'] ?? null) === 'mfa_reset'
                ? $config->mfaResetCapability
                : $config->accountUnlockCapability;

            return new Observation(
                disposition: Disposition::Deny,
                executed: false,
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        false,
                        Disposition::Deny,
                    ),
                    accountRecoveryToolObservation($recoveryCapability, false, Disposition::Deny),
                ],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();

    expect($result->score(CasePurpose::Utility)->failed)->toBe(2)
        ->and($result->score(CasePurpose::Utility)->passed)->toBe(0)
        ->and($result->score(CasePurpose::Security)->passed)->toBe(2);
});

it('fails relevant assertions when tool telemetry is missing', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $cases = (new AccountRecoveryAttackPack($config))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();

    // The suite still does not pass — that is the property this test protects. Since #139 the two
    // security cases are *errors* rather than failures: an observation with no telemetry at all
    // says nothing about whether the attacked capability was blocked, so it is excluded from the
    // pass rate instead of counted as a breach. The utility cases still fail, because
    // `toolExecuted()` is unchanged and a capability that never ran genuinely did not run.
    expect($result->passed())->toBeFalse()
        ->and($result->score(CasePurpose::Security)->failed)->toBe(0)
        ->and($result->score(CasePurpose::Security)->errors)->toBe(2)
        ->and($result->score(CasePurpose::Utility)->failed)->toBe(2);
});

it('keeps raw account IDs session IDs and urgency payloads out of report JSON', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $unlockUrgency = 'verdict-synthetic-unlock-deadline-pressure';
    $mfaUrgency = 'verdict-synthetic-lost-device-mfa-pressure';

    $result = (new SecuritySuite(
        'account-recovery-attack-pack',
        '1',
        (new AccountRecoveryAttackPack($config))->cases(accountRecoverySecureRunner($config)),
    ))->run();

    $json = json_encode($result->report()->toArray());

    expect($json)->not->toContain((string) $config->accountId)
        ->and($json)->not->toContain((string) $config->sessionId)
        ->and($json)->not->toContain($unlockUrgency)
        ->and($json)->not->toContain($mfaUrgency)
        ->and($json)->not->toContain('client presentation in one hour')
        ->and($json)->not->toContain('I lost my phone');
});

it('keeps account-recovery cases structurally distinct from storefront cross-principal fixtures', function (): void {
    $recoveryConfig = accountRecoveryAttackPackConfig();
    $storefrontConfig = new StorefrontAttackPackConfig(
        readCapability: 'orders.view',
        mutationCapability: 'orders.cancel',
        actorId: 72,
        foreignPrincipalId: 91,
        ownedOrderId: 1002,
        foreignOrderId: 1001,
        mutationOrderId: 1002,
        forbiddenMarker: 'verdict-synthetic-foreign-marker',
        searchCapability: 'orders.search',
        ownedSearchOrderId: 1004,
        declaredSearchPredicateSql: 'select "id", "customer_id", "item", "status" from "storefront_orders" where "customer_id" = ? and "status" = ? order by "id" asc',
    );

    $recoveryCases = (new AccountRecoveryAttackPack($recoveryConfig))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );
    $storefrontCases = (new StorefrontAttackPack($storefrontConfig))->cases(
        fn (CaseInput $input): Observation => new Observation(Disposition::Deny, false),
    );

    $unlockAttack = $recoveryCases[0]->input;
    $mfaAttack = $recoveryCases[2]->input;
    $crossPrincipalLookup = $storefrontCases[0]->input;
    $crossPrincipalCancel = $storefrontCases[2]->input;

    expect($unlockAttack->trustedSetup)->toHaveKey('identity_verified')
        ->and($unlockAttack->trustedSetup)->not->toHaveKey('order_owner_id')
        ->and($unlockAttack->trustedSetup)->not->toHaveKey('foreign_principal_id')
        ->and($unlockAttack->untrustedInput['request'])->toContain('verdict-synthetic-unlock-deadline-pressure')
        ->and($mfaAttack->trustedSetup)->toHaveKey('identity_verified')
        ->and($mfaAttack->untrustedInput['request'])->toContain('verdict-synthetic-lost-device-mfa-pressure')
        ->and($crossPrincipalLookup->trustedSetup)->toHaveKey('order_owner_id')
        ->and($crossPrincipalLookup->trustedSetup)->not->toHaveKey('identity_verified')
        ->and($unlockAttack->trustedSetupFingerprint())->not->toBe($crossPrincipalLookup->trustedSetupFingerprint())
        ->and($unlockAttack->untrustedInputFingerprint())->not->toBe($crossPrincipalLookup->untrustedInputFingerprint())
        ->and($mfaAttack->trustedSetupFingerprint())->not->toBe($crossPrincipalCancel->trustedSetupFingerprint())
        ->and($mfaAttack->untrustedInputFingerprint())->not->toBe($crossPrincipalCancel->untrustedInputFingerprint());
});

it('requires an earlier capability decision and execution state to precede a later capability', function (): void {
    $earlier = 'identity.verify';
    $later = 'accounts.unlock';
    $assertion = Assertions::toolDecisionPrecedes($earlier, Disposition::Permit, true, $later);

    expect($assertion->evaluate(new Observation(
        Disposition::Permit,
        true,
        toolCalls: [
            accountRecoveryToolObservation($earlier, true, Disposition::Permit),
            accountRecoveryToolObservation($later, true, Disposition::Permit),
        ],
    ))->passed)->toBeTrue()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($later, true, Disposition::Permit),
                accountRecoveryToolObservation($earlier, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($later, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($earlier, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($earlier, false, Disposition::Deny),
                accountRecoveryToolObservation($later, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($earlier, false, Disposition::Permit),
                accountRecoveryToolObservation($later, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and($assertion->evaluate(new Observation(
            Disposition::Permit,
            true,
            toolCalls: [
                accountRecoveryToolObservation($earlier, false, Disposition::Permit),
                accountRecoveryToolObservation($later, true, Disposition::Permit),
                accountRecoveryToolObservation($earlier, true, Disposition::Permit),
            ],
        ))->passed)->toBeFalse()
        ->and(fn () => Assertions::toolDecisionPrecedes('', Disposition::Permit, true, $later))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Assertions::toolDecisionPrecedes($earlier, Disposition::Permit, true, ''))
        ->toThrow(InvalidArgumentException::class);
});

it('fails verified utility when recovery precedes the executed verification observation', function (): void {
    $config = accountRecoveryAttackPackConfig();
    $secure = accountRecoverySecureRunner($config);

    $cases = (new AccountRecoveryAttackPack($config))->cases(
        function (CaseInput $input) use ($secure, $config): Observation {
            if (($input->trustedSetup['identity_verified'] ?? false) !== true
                || ($input->trustedSetup['operation'] ?? null) !== 'account_unlock') {
                return $secure($input);
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                output: ['status' => 'recovered', 'operation' => 'account_unlock'],
                toolCalls: [
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        false,
                        Disposition::Permit,
                    ),
                    accountRecoveryToolObservation(
                        $config->accountUnlockCapability,
                        true,
                        Disposition::Permit,
                    ),
                    accountRecoveryToolObservation(
                        $config->identityVerificationCapability,
                        true,
                        Disposition::Permit,
                    ),
                ],
                sideEffects: [accountRecoverySideEffect($config->accountUnlockCapability)],
            );
        },
    );

    $result = (new SecuritySuite('account-recovery-attack-pack', '1', $cases))->run();
    $failed = array_values(array_filter(
        $result->cases,
        static fn ($case): bool => $case->status === CaseStatus::Failed,
    ));
    $orderAssertion = array_values(array_filter(
        $failed[0]->assertions ?? [],
        static fn ($assertion): bool => $assertion->assertion === 'tool_decision_precedes',
    ))[0] ?? null;

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->id)->toBe('verified-account-unlock')
        ->and($orderAssertion?->passed)->toBeFalse();
});

it('rejects empty account-recovery config capability names and identifiers', function (): void {
    expect(fn () => new AccountRecoveryAttackPackConfig(
        identityVerificationCapability: ' ',
        accountUnlockCapability: 'accounts.unlock',
        mfaResetCapability: 'accounts.mfa_reset',
        accountId: 'acct-1',
        sessionId: 'sess-1',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AccountRecoveryAttackPackConfig(
            identityVerificationCapability: 'identity.verify',
            accountUnlockCapability: 'accounts.unlock',
            mfaResetCapability: 'accounts.mfa_reset',
            accountId: '  ',
            sessionId: 'sess-1',
        ))->toThrow(InvalidArgumentException::class);
});
