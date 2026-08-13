<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use Fissible\Verdict\Contracts\ContextTransformer;
use LogicException;

final readonly class PendingContextRelease
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @param  list<ContextTransformer>  $transformers
     */
    public function __construct(
        private ContextReleaseManager $manager,
        private array $payload,
        private ?Source $source = null,
        private ?Trust $trust = null,
        private ?DataClass $dataClass = null,
        private array $paths = [],
        private array $transformers = [],
        private bool $validateFieldPaths = true,
    ) {}

    public function source(Source $source): self
    {
        return new self($this->manager, $this->payload, $source, $this->trust, $this->dataClass, $this->paths, $this->transformers, $this->validateFieldPaths);
    }

    public function trust(Trust $trust): self
    {
        return new self($this->manager, $this->payload, $this->source, $trust, $this->dataClass, $this->paths, $this->transformers, $this->validateFieldPaths);
    }

    public function classify(DataClass $dataClass): self
    {
        return new self($this->manager, $this->payload, $this->source, $this->trust, $dataClass, $this->paths, $this->transformers, $this->validateFieldPaths);
    }

    /** @param list<string> $paths */
    public function only(array $paths): self
    {
        return new self($this->manager, $this->payload, $this->source, $this->trust, $this->dataClass, $paths, $this->transformers, $this->validateFieldPaths);
    }

    public function through(ContextTransformer ...$transformers): self
    {
        return new self(
            $this->manager,
            $this->payload,
            $this->source,
            $this->trust,
            $this->dataClass,
            $this->paths,
            array_values([...$this->transformers, ...$transformers]),
            $this->validateFieldPaths,
        );
    }

    /** @param list<string> $paths */
    public function redact(array $paths, string $replacement = '[REDACTED]'): self
    {
        return $this->through(new StructuredRedactor($paths, $replacement));
    }

    /**
     * Accept transformer field paths the release allowlist can never match.
     *
     * The escape hatch for what the validation cannot see: a projection whose shape varies across
     * releases in ways the operator knows about. It is deliberately explicit at the call site.
     */
    public function withoutFieldPathValidation(): self
    {
        return new self(
            $this->manager,
            $this->payload,
            $this->source,
            $this->trust,
            $this->dataClass,
            $this->paths,
            $this->transformers,
            false,
        );
    }

    public function to(Destination $destination): ContextReleaseResult
    {
        if ($this->source === null || $this->trust === null || $this->dataClass === null) {
            throw new LogicException('A context release must declare its source, trust, and data classification.');
        }

        return $this->manager->release(
            payload: $this->payload,
            source: $this->source,
            trust: $this->trust,
            dataClass: $this->dataClass,
            paths: $this->paths,
            destination: $destination,
            transformers: $this->transformers,
            validateFieldPaths: $this->validateFieldPaths,
        );
    }
}
