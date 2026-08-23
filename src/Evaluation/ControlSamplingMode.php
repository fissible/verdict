<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * How a control run's model was decoded — declared by the application, because decoding
 * configuration is application-owned and Verdict cannot verify it (the ADR 0020 posture).
 *
 * The declaration is load-bearing: only greedy decoding with a fixed seed makes "trial 3 guarded"
 * and "trial 3 control" a matched pair, so only greedy runs classify the 2×2. A sampled run's arms
 * are independent draws and are reported as per-arm marginals with no pairing claimed.
 *
 * See [ADR 0023](../../docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md).
 */
enum ControlSamplingMode: string
{
    case Greedy = 'greedy';
    case Sampled = 'sampled';
}
