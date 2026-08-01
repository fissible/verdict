<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;

final readonly class ContextReleaseEvidence
{
    /**
     * @param  list<string>  $requestedPathFingerprints
     * @param  list<string>  $releasedPathFingerprints
     * @param  list<string>  $transformFingerprints
     * @param  list<string>  $transformedPathFingerprints
     */
    public function __construct(
        public string $source,
        public string $destination,
        public string $trustZone,
        public Trust $trust,
        public DataClass $dataClass,
        public string $disposition,
        public string $reason,
        public array $requestedPathFingerprints,
        public array $releasedPathFingerprints,
        public ?string $payloadFingerprint,
        public DateTimeImmutable $recordedAt,
        public array $transformFingerprints = [],
        public array $transformedPathFingerprints = [],
    ) {}

    /**
     * @param  list<string>  $requestedPaths
     * @param  list<string>  $releasedPaths
     * @param  list<string>  $transformNames
     * @param  list<string>  $transformedPaths
     */
    public static function make(
        Source $source,
        Destination $destination,
        Trust $trust,
        DataClass $dataClass,
        bool $permitted,
        string $reason,
        array $requestedPaths,
        array $releasedPaths,
        ?string $payloadFingerprint,
        DateTimeImmutable $recordedAt,
        array $transformNames = [],
        array $transformedPaths = [],
    ): self {
        return new self(
            source: $source->identity(),
            destination: $destination->identity(),
            trustZone: $destination->trustZone,
            trust: $trust,
            dataClass: $dataClass,
            disposition: $permitted ? 'permit' : 'deny',
            reason: $reason,
            requestedPathFingerprints: array_map(self::fingerprintPath(...), $requestedPaths),
            releasedPathFingerprints: array_map(self::fingerprintPath(...), $releasedPaths),
            payloadFingerprint: $payloadFingerprint,
            recordedAt: $recordedAt,
            transformFingerprints: array_map(self::fingerprintPath(...), $transformNames),
            transformedPathFingerprints: array_map(self::fingerprintPath(...), $transformedPaths),
        );
    }

    private static function fingerprintPath(string $path): string
    {
        return hash('sha256', $path);
    }
}
