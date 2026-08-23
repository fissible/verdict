<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

/**
 * Which channel a capability's target resolver reads from.
 *
 * This names the **constructor that was used**, not a verified property of the closure body. Verdict
 * cannot see inside a resolver, so a capability built with `Capability::usingPolicy()` is recorded as
 * `Proposal` even if its closure happens to touch only context. See
 * [ADR 0025](../../docs/adr/0025-target-provenance-is-proven-where-it-can-be.md).
 */
enum TargetSource: string
{
    /**
     * The resolver receives an `ActionContext` and cannot read the proposal. Enforced by parameter
     * types: an injected argument cannot redirect which record is acted on.
     */
    case Context = 'context';

    /**
     * The resolver receives an `ActionEnvelope` and may read model-supplied arguments. Scoping the
     * lookup to the actor bounds authority; it does not bound which of the actor's records is chosen.
     */
    case Proposal = 'proposal';
}
