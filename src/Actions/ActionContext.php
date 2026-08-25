<?php

declare(strict_types=1);

namespace Fissible\Verdict\Actions;

final readonly class ActionContext
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string|int>  $approvalContext  application-owned binding identifiers
     *                                                      (tenant, conversation, …) captured
     *                                                      verbatim onto any approval receipt
     *                                                      issued for this action, so per-receipt
     *                                                      authorization has something to check.
     *                                                      Scalars only, deliberately: this is not
     *                                                      a place for payloads or metadata.
     */
    public function __construct(
        public mixed $actor,
        public array $metadata = [],
        public mixed $subject = null,
        public array $approvalContext = [],
    ) {}
}
