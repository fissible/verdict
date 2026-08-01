<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

final readonly class ReleasePolicy
{
    /**
     * @param  list<DataClass>  $dataClasses
     * @param  list<Trust>  $trustLevels
     */
    private function __construct(
        public Source $source,
        public Destination $destination,
        private array $dataClasses = [],
        private array $trustLevels = [],
    ) {}

    public static function between(Source $source, Destination $destination): self
    {
        return new self($source, $destination);
    }

    public function allow(DataClass ...$dataClasses): self
    {
        $unique = [];

        foreach ([...$this->dataClasses, ...$dataClasses] as $dataClass) {
            $unique[$dataClass->value] = $dataClass;
        }

        return new self(
            source: $this->source,
            destination: $this->destination,
            dataClasses: array_values($unique),
            trustLevels: $this->trustLevels,
        );
    }

    public function whenTrustIs(Trust ...$trustLevels): self
    {
        $unique = [];

        foreach ([...$this->trustLevels, ...$trustLevels] as $trust) {
            $unique[$trust->value] = $trust;
        }

        return new self(
            source: $this->source,
            destination: $this->destination,
            dataClasses: $this->dataClasses,
            trustLevels: array_values($unique),
        );
    }

    public function permits(Source $source, Destination $destination, DataClass $dataClass, Trust $trust): bool
    {
        return $source->identity() === $this->source->identity()
            && $destination->identity() === $this->destination->identity()
            && in_array($dataClass, $this->dataClasses, true)
            && in_array($trust, $this->trustLevels, true);
    }

    public function routeKey(): string
    {
        return $this->source->identity().'->'.$this->destination->identity();
    }
}
