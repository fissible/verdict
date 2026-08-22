<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Actions\ActionEnvelope;

/**
 * Wraps a capability executor's invocation — and nothing else.
 *
 * `VerdictManager` opens the window around exactly the executor call, after every gate has
 * admitted the action and before any post-execution finalization: authorization, evidence
 * recording, approval-receipt, rate-limit, and execution-claim store traffic all run outside it
 * by construction. That placement is what the evaluation harness's predicate capture (#251)
 * depends on — a statement observed inside the window is the executor's, never the boundary's own
 * bookkeeping — and it is structural: every capability execution passes through the one call site
 * that opens the window, so no executor path can forget to opt in.
 *
 * The envelope identifies the execution for attribution. Implementations must return the
 * execution's result unchanged and let its exceptions propagate: the window observes an
 * execution, it never alters one.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
interface ExecutionWindow
{
    public function around(ActionEnvelope $envelope, callable $execution): mixed;
}
