<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;

/**
 * Fail-closed signal for a malformed verdict.approvals.consumed_binding_guard config: the keyed-digest
 * guard refuses to build a scheme rather than silently degrading to keyless (ADR 0039).
 */
final class InvalidConsumedBindingGuardConfig extends RuntimeException {}
