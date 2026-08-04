<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use InvalidArgumentException;

final readonly class ProvenanceLedger
{
    public function __construct(
        private EvidenceRecorder $evidence,
        private Clock $clock,
    ) {}

    public function record(
        string $correlationId,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
        ContextChannel $channel,
        mixed $content,
        ?string $componentLabel = null,
        ?string $componentVersion = null,
    ): ProvenanceEntry {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');

        if ($componentLabel !== null) {
            ProvenanceEntry::assertIdentifier($componentLabel, 'Provenance component');
        }

        if ($componentVersion !== null) {
            ProvenanceEntry::assertIdentifier($componentVersion, 'Provenance component version');
        }

        if ($componentVersion !== null && $componentLabel === null) {
            throw new InvalidArgumentException('A provenance component version requires a component label.');
        }

        $entry = new ProvenanceEntry(
            correlationId: $correlationId,
            source: $source,
            trust: $trust,
            dataClass: $dataClass,
            channel: $channel,
            contentFingerprint: ContentFingerprint::make($content),
            componentLabel: $componentLabel,
            componentFingerprint: $componentVersion === null
                ? null
                : ContentFingerprint::make($componentVersion),
            recordedAt: $this->clock->now(),
        );

        $this->evidence->recordProvenance($entry);

        return $entry;
    }

    /** @return list<ProvenanceEntry> */
    public function forCorrelation(string $correlationId): array
    {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');

        return $this->evidence->provenanceFor($correlationId);
    }
}
