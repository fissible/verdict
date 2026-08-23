<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\Events\ConsequentialActionUnrecorded;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Emits {@see ConsequentialActionUnrecorded} at most once per process — the first time a capability
 * requiring confirmation or at-most-once execution is seen running under the shipped no-op recorder.
 *
 * Bound as a singleton so the once-per-process guard survives `VerdictManager`'s lifecycle: a scoped
 * `VerdictManager` is rebuilt per scope (e.g. per live-evaluation trial), and the warning must be
 * process-scoped, not per-action or per-scope. See #194.
 *
 * The check is deliberately `instanceof NullEvidenceRecorder`, not "any writer that records
 * nothing": it warns about the *shipped default* going unnoticed. An application that deliberately
 * binds its own no-op writer has made an explicit choice and is not second-guessed.
 */
final class NullRecorderWarning
{
    private bool $warned = false;

    public function noteConsequentialAction(EvidenceWriter $writer, Dispatcher $events): void
    {
        if ($this->warned || ! $writer instanceof NullEvidenceRecorder) {
            return;
        }

        $this->warned = true;
        $events->dispatch(new ConsequentialActionUnrecorded);
    }
}
