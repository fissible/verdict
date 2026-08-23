<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * The control arm of a paired live evaluation run: the same suite, unguarded, with its outcomes
 * per case and — under greedy decoding only — the per-trial pair classification against the
 * guarded arm.
 *
 * See [ADR 0023](../../docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md).
 */
final readonly class LiveEvaluationControlResult
{
    /**
     * @param  list<LiveEvaluationControlCaseResult>  $cases
     */
    public function __construct(
        public ControlSamplingMode $samplingMode,
        public array $cases,
    ) {}
}
