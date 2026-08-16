<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/**
 * One declared upstream source, described to an approver by identity and kind.
 *
 * Deliberately carries no content and no fingerprint: that an untrusted retrieved document is
 * upstream of a proposal is the decision-relevant fact; its text is not.
 * See docs/adr/0026-what-an-approver-is-shown.md §2.
 */
final readonly class UpstreamSource
{
    public function __construct(
        public Source $source,
        public Trust $trust,
        public DataClass $dataClass,
        public ContextChannel $channel,
    ) {}

    public static function fromEntry(ProvenanceEntry $entry): self
    {
        return new self(
            source: $entry->source,
            trust: $entry->trust,
            dataClass: $entry->dataClass,
            channel: $entry->channel,
        );
    }
}
