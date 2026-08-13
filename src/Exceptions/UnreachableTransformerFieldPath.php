<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use LogicException;

/**
 * A transformer was configured with a field path that no payload can ever match.
 *
 * This is the typo case: a redaction naming user.social_security when the allowlist permits
 * user.socialSecurity scrubs nothing, and the field is released in full. It is distinct from a
 * path that matches no instances today — a wildcard over an empty collection, or an optional
 * field absent from this record — both of which are legitimate and are not reported here.
 */
final class UnreachableTransformerFieldPath extends LogicException
{
    /** @param list<string> $paths */
    private function __construct(
        public readonly string $transformer,
        public readonly array $paths,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $paths */
    public static function forTransformer(string $transformer, array $paths): self
    {
        $rendered = implode(', ', $paths);

        return new self(
            $transformer,
            $paths,
            "The [{$transformer}] transformer declares field path(s) [{$rendered}] that no allowed field path can "
                .'ever match, so they would silently transform nothing. Correct the path, add it to the release '
                .'allowlist, or call withoutFieldPathValidation() to accept this deliberately.',
        );
    }
}
