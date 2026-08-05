<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Contracts\ClassifiesToolResult;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Laravel\Ai\Events\ToolInvoked;
use Stringable;

final readonly class RecordToolResultProvenance
{
    public function __construct(private ProvenanceLedger $provenance) {}

    public function handle(ToolInvoked $event): void
    {
        if (! $event->tool instanceof ClassifiesToolResult) {
            return;
        }

        $result = $event->result instanceof Stringable
            ? (string) $event->result
            : $event->result;

        $this->provenance->record(
            correlationId: $event->invocationId,
            source: $event->tool->provenanceSource(),
            trust: $event->tool->provenanceTrust(),
            dataClass: $event->tool->provenanceDataClass(),
            channel: ContextChannel::ToolResult,
            content: $result,
        );
    }
}
