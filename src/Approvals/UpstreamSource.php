<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use InvalidArgumentException;

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

    /** @return array{source_kind: string, source_name: string, trust: string, data_class: string, channel: string} */
    public function toArray(): array
    {
        return [
            'source_kind' => $this->source->kind,
            'source_name' => $this->source->name,
            'trust' => $this->trust->value,
            'data_class' => $this->dataClass->value,
            'channel' => $this->channel->value,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public static function fromArray(array $attributes): self
    {
        $kind = $attributes['source_kind'] ?? null;
        $name = $attributes['source_name'] ?? null;

        if (! is_string($kind) || ! is_string($name)) {
            throw new InvalidArgumentException('A stored upstream source requires a source kind and name.');
        }

        return new self(
            source: match ($kind) {
                'application' => Source::application($name),
                'user' => Source::user($name),
                'external' => Source::external($name),
                default => throw new InvalidArgumentException("Unknown upstream source kind [{$kind}]."),
            },
            trust: Trust::from((string) ($attributes['trust'] ?? '')),
            dataClass: DataClass::from((string) ($attributes['data_class'] ?? '')),
            channel: ContextChannel::from((string) ($attributes['channel'] ?? '')),
        );
    }
}
