<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support\Evaluation;

/**
 * The single roster of shipped-pack reference wirings. Everything that
 * enumerates the packs — the report runner, the baseline refresh, and the
 * committed-baseline tests — reads this list, so adding a pack in one place
 * is enough and forgetting one is loud.
 */
final class PackReferences
{
    /**
     * @var list<class-string<AccountRecoveryReference|DelegationConfusionReference|RagBorneInjectionReference|StorefrontReference|ToolIntegrityReference>>
     */
    public const array ALL = [
        AccountRecoveryReference::class,
        DelegationConfusionReference::class,
        RagBorneInjectionReference::class,
        StorefrontReference::class,
        ToolIntegrityReference::class,
    ];
}
