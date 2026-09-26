<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use InvalidArgumentException;

final readonly class ProvenanceEntry
{
    public function __construct(
        public string $correlationId,
        public Source $source,
        public Trust $trust,
        public DataClass $dataClass,
        public ContextChannel $channel,
        public string $contentFingerprint,
        public ?string $componentLabel,
        public ?string $componentFingerprint,
        public DateTimeImmutable $recordedAt,
    ) {
        self::assertIdentifier($this->correlationId, 'Provenance correlation');

        if ($this->componentLabel !== null) {
            self::assertIdentifier($this->componentLabel, 'Provenance component');
        }

        self::assertFingerprint($this->contentFingerprint, 'content');

        if ($this->componentFingerprint !== null) {
            self::assertFingerprint($this->componentFingerprint, 'component');
        }

        if ($this->componentFingerprint !== null && $this->componentLabel === null) {
            throw new InvalidArgumentException('A provenance component fingerprint requires a component label.');
        }
    }

    /**
     * A deterministic read order derived entirely from the entry, so the database and in-memory
     * recorders agree and the order is reproducible from the data — never from a storage id or the
     * insertion sequence (#480). Wall-clock second precision matches the stored recorded_at; every
     * key is strcmp so two numeric-looking fingerprints never tie the way array <=> would (#482).
     */
    public static function readOrder(self $a, self $b): int
    {
        return strcmp($a->recordedAt->format('Y-m-d H:i:s'), $b->recordedAt->format('Y-m-d H:i:s'))
            ?: strcmp($a->contentFingerprint, $b->contentFingerprint)
            ?: strcmp($a->componentFingerprint ?? '', $b->componentFingerprint ?? '')
            ?: strcmp($a->componentLabel ?? '', $b->componentLabel ?? '')
            ?: strcmp($a->source->identity(), $b->source->identity())
            ?: strcmp($a->channel->value, $b->channel->value)
            ?: strcmp($a->trust->value, $b->trust->value)
            ?: strcmp($a->dataClass->value, $b->dataClass->value);
    }

    public static function assertIdentifier(string $value, string $label): void
    {
        if (trim($value) === '' || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            throw new InvalidArgumentException("{$label} identifiers may only contain letters, numbers, dots, underscores, and hyphens.");
        }
    }

    public static function assertFingerprint(string $fingerprint, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new InvalidArgumentException("The provenance {$label} fingerprint must be a lowercase SHA-256 digest.");
        }
    }
}
