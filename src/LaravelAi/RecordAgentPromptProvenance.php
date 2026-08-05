<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Laravel\Ai\Events\PromptingAgent;

final readonly class RecordAgentPromptProvenance
{
    public function __construct(
        private ProvenanceLedger $provenance,
        private PromptProvenanceRegistry $registry,
    ) {}

    public function handle(PromptingAgent $event): void
    {
        if ($event->prompt->hasApprovalDecisions() || $event->prompt->invocationId !== null) {
            return;
        }

        $pending = $this->registry->consume($event->prompt->agent);

        if ($pending === null) {
            return;
        }

        $this->provenance->record(
            correlationId: $event->invocationId,
            source: $pending['source'],
            trust: $pending['trust'],
            dataClass: $pending['dataClass'],
            channel: ContextChannel::UserInput,
            content: $pending['prompt'],
        );
    }
}
