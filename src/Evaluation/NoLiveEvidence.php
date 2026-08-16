<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\LiveEvidenceReader;
use Fissible\Verdict\Evidence\DecisionEvidence;

/**
 * The evidence reader of an arm that produces no evidence by construction: the unguarded control
 * arm has no Verdict in the path, so there are no decisions to read and no correlation to assert.
 * `LiveAgentObserver` treats this reader's presence as the unguarded mode switch.
 *
 * @internal Construct only through {@see LiveAgentObserver::unguarded()}. Handing this to the
 *           guarded observer constructor would disable the misconfiguration check that
 *           correlation exists to provide — a captured call with no evidence would classify as a
 *           decline instead of the harness fault it is.
 */
final class NoLiveEvidence implements LiveEvidenceReader
{
    /** @return list<DecisionEvidence> */
    public function decisionsFor(string $invocationId): array
    {
        return [];
    }
}
