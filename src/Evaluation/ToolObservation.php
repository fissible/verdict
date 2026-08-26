<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use InvalidArgumentException;

final readonly class ToolObservation
{
    public function __construct(
        public string $capability,
        public string $argumentFingerprint,
        public ?Disposition $disposition,
        public bool $executed,
        /**
         * The labels of the registered canaries found in this call's arguments (ADR 0032).
         *
         * Labels only — never a canary value, never a matched fragment, never the argument. A
         * label names the canary; it is not the canary.
         *
         * @var list<string>
         */
        public array $matchedRegisteredSecrets = [],
        /**
         * The labels this call was actually scanned against.
         *
         * Recorded beside the matches so an empty match list is never ambiguous: "scanned, clean"
         * and "the instrument was never armed" are different facts, and only this field separates
         * them. Without it an assertion reading matches alone passes vacuously whenever the
         * registration wiring is missed — the failure mode #183/#185 already cost this project,
         * and the reason ADR 0024 gates integrity before coverage.
         *
         * @var list<string>
         */
        public array $registeredSecretLabels = [],
    ) {
        if (trim($this->capability) === '') {
            throw new InvalidArgumentException('A tool observation must name a capability.');
        }

        if (preg_match('/^[a-f0-9]{64}\z/', $this->argumentFingerprint) !== 1) {
            throw new InvalidArgumentException('A tool observation requires a SHA-256 argument fingerprint.');
        }

        $this->assertLabels($this->matchedRegisteredSecrets);
        $this->assertLabels($this->registeredSecretLabels);

        // A matched label outside the scanned set means the two halves were assembled from
        // different places, and the pair can no longer be read as "these were scanned; these hit".
        $ghosts = array_diff($this->matchedRegisteredSecrets, $this->registeredSecretLabels);

        if ($ghosts !== []) {
            throw new InvalidArgumentException(
                'A tool observation matched registered secrets it was not scanned against: '
                .implode(', ', $ghosts).'.'
            );
        }
    }

    /**
     * Declared as mixed values on purpose: `list<string>` is a docblock, not a runtime guarantee,
     * so a label array assembled from untyped data still reaches here unchecked. Mirrors
     * {@see Observation::assertToolCalls()}.
     *
     * @param  array<array-key, mixed>  $labels
     */
    private function assertLabels(array $labels): void
    {
        foreach ($labels as $label) {
            if (! is_string($label)) {
                throw new InvalidArgumentException('Registered-secret labels must be strings.');
            }
        }
    }
}
