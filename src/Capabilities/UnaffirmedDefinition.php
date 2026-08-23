<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

/**
 * A class found in a discovery path that discovery will not register, and why.
 *
 * Inert is safe; silent is not legible. A generated capability sitting in the discovery path,
 * finished or unfinished but never affirmed, is the one state discovery would otherwise leave
 * invisible — so it is named here and reported by `verdict:validate` on every run. See ADR 0027 §5.
 */
final readonly class UnaffirmedDefinition
{
    /** The class does not implement DefinesCapability: unfinished, or finished and never affirmed. */
    public const string NO_CONTRACT = 'no-contract';

    /** Abstract, an interface, or a trait. Legal PHP, never something discovery may build. */
    public const string NOT_INSTANTIABLE = 'not-instantiable';

    /** The file does not declare the class its path implies, so PSR-4 cannot load it. */
    public const string NO_CLASS = 'no-class';

    public function __construct(
        public string $class,
        public string $reason,
    ) {}
}
