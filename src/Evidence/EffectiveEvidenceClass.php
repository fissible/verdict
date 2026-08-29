<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use LogicException;

/**
 * Resolves the configured class responsible for durable evidence writes.
 */
final class EffectiveEvidenceClass
{
    /** @return class-string */
    public static function resolve(): string
    {
        $writer = config('verdict.evidence.writer');
        $effective = is_string($writer) && $writer !== ''
            ? $writer
            : config('verdict.evidence.recorder', NullEvidenceRecorder::class);

        if (! is_string($effective) || ! class_exists($effective)) {
            throw new LogicException('The Verdict effective evidence configuration must contain a class name.');
        }

        return $effective;
    }
}
