<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Decisions\ExecutionResult;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use InvalidArgumentException;

final readonly class Observation
{
    /**
     * @param  list<ToolObservation>  $toolCalls
     * @param  list<string>  $sideEffects
     */
    public function __construct(
        public ?Disposition $disposition,
        public bool $executed,
        public mixed $output = null,
        public array $toolCalls = [],
        public array $sideEffects = [],
    ) {
        $this->assertToolCalls($this->toolCalls);
        $this->assertSideEffects($this->sideEffects);
    }

    /**
     * @param  list<string>  $sideEffects
     */
    public static function fromExecutionResult(ExecutionResult $result, array $sideEffects = []): self
    {
        $proposal = $result->evaluation->envelope->proposal;

        return new self(
            disposition: $result->evaluation->decision->disposition,
            executed: $result->executed,
            output: $result->output,
            toolCalls: [new ToolObservation(
                capability: $proposal->capability,
                argumentFingerprint: ArgumentFingerprint::make($proposal->arguments),
                disposition: $result->evaluation->decision->disposition,
                executed: $result->executed,
            )],
            sideEffects: $sideEffects,
        );
    }

    /**
     * @param  array<array-key, mixed>  $toolCalls
     */
    private function assertToolCalls(array $toolCalls): void
    {
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof ToolObservation) {
                throw new InvalidArgumentException('Every observed tool call must be a ToolObservation.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $sideEffects
     */
    private function assertSideEffects(array $sideEffects): void
    {
        foreach ($sideEffects as $sideEffect) {
            if (! is_string($sideEffect) || trim($sideEffect) === '') {
                throw new InvalidArgumentException('Every observed side effect must have a non-empty name.');
            }
        }
    }
}
