<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class AccountRecoveryAttackPackConfig
{
    use AttackPackConfigValidation;

    public function __construct(
        public string $identityVerificationCapability,
        public string $accountUnlockCapability,
        public string $mfaResetCapability,
        public string|int $accountId,
        public string|int $sessionId,
    ) {
        $this->requireNonEmptyString(
            $this->identityVerificationCapability,
            'An account-recovery attack pack must name an identity-verification capability.',
        );
        $this->requireNonEmptyString(
            $this->accountUnlockCapability,
            'An account-recovery attack pack must name an account-unlock capability.',
        );
        $this->requireNonEmptyString(
            $this->mfaResetCapability,
            'An account-recovery attack pack must name an MFA-reset capability.',
        );
        $this->requireIdentifier(
            $this->accountId,
            'An account-recovery attack pack account ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->sessionId,
            'An account-recovery attack pack session ID must be a non-empty string or integer.',
        );
    }
}
