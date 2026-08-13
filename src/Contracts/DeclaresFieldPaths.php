<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * A transformer that can declare which field paths it was configured to act on.
 *
 * Deliberately separate from ContextTransformer, which is a documented stable extension point:
 * widening that interface would break every application-supplied transformer. A transformer that
 * does not implement this is simply skipped by release-time field-path validation.
 */
interface DeclaresFieldPaths
{
    /** @return list<string> */
    public function declaredFieldPaths(): array;
}
