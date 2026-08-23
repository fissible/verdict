<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Closure;
use Fissible\Verdict\Contracts\ObservationAssertion;
use InvalidArgumentException;

final readonly class CallbackAssertion implements ObservationAssertion
{
    /** @var Closure(Observation): bool */
    private Closure $test;

    /**
     * @param  Closure(Observation): bool  $test
     */
    public function __construct(
        public string $name,
        Closure $test,
        public string $failureMessage = 'The observed behavior did not satisfy the assertion.',
        public AssertionFacet $facet = AssertionFacet::Security,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('An observation assertion must have a name.');
        }

        $this->test = $test;
    }

    public function evaluate(Observation $observation): AssertionResult
    {
        $passed = ($this->test)($observation);

        return new AssertionResult(
            assertion: $this->name,
            passed: $passed,
            message: $passed ? null : $this->failureMessage,
            facet: $this->facet,
        );
    }
}
