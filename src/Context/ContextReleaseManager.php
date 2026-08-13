<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ContextTransformer;
use Fissible\Verdict\Contracts\DeclaresFieldPaths;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Exceptions\UnreachableTransformerFieldPath;
use Fissible\Verdict\LaravelAi\InvocationContext;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use LogicException;

final readonly class ContextReleaseManager
{
    /**
     * @internal Resolve ContextReleaseManager from the container. This constructor is not part of the
     *           supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private ReleasePolicyRegistry $policies,
        private FieldProjector $projector,
        private EvidenceWriter $evidence,
        private Clock $clock,
        private InvocationContext $invocations,
        private ProvenanceLedger $provenance,
    ) {}

    public function policy(ReleasePolicy $policy): self
    {
        $this->policies->register($policy);

        return $this;
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $payload
     */
    public function prepare(array|Arrayable $payload): PendingContextRelease
    {
        return new PendingContextRelease($this, $payload instanceof Arrayable ? $payload->toArray() : $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @param  list<ContextTransformer>  $transformers
     */
    public function release(
        array $payload,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
        array $paths,
        Destination $destination,
        array $transformers = [],
        bool $validateFieldPaths = true,
    ): ContextReleaseResult {
        if ($validateFieldPaths) {
            $this->assertTransformerFieldPathsAreReachable($paths, $transformers);
        }

        $transformNames = array_map(function (ContextTransformer $transformer): string {
            $name = $transformer->name();

            if (trim($name) === '') {
                throw new LogicException('A context transformer must have a non-empty name.');
            }

            return $name;
        }, $transformers);

        if (! $this->policies->permits($source, $destination, $dataClass, $trust)) {
            $evidence = ContextReleaseEvidence::make(
                source: $source,
                destination: $destination,
                trust: $trust,
                dataClass: $dataClass,
                permitted: false,
                reason: 'No registered context release policy permits this route.',
                requestedPaths: $paths,
                releasedPaths: [],
                payloadFingerprint: null,
                recordedAt: $this->clock->now(),
                transformNames: $transformNames,
                invocationId: $this->invocations->current(),
            );
            $this->evidence->recordRelease($evidence);

            return ContextReleaseResult::denied($evidence);
        }

        $released = $this->projector->project($payload, $paths);
        $projectedPaths = array_keys(Arr::dot($released));
        $transformedPaths = [];

        foreach ($transformers as $transformer) {
            $transformation = $transformer->transform($released);
            $resultPaths = array_keys(Arr::dot($transformation->payload));

            if (array_diff($resultPaths, $projectedPaths) !== []) {
                throw new LogicException('A context transformer must not expand the explicitly projected field set.');
            }

            if (array_diff($transformation->transformedPaths, $projectedPaths) !== []) {
                throw new LogicException('A context transformer must report only explicitly projected field paths.');
            }

            $released = $transformation->payload;
            $transformedPaths = [...$transformedPaths, ...$transformation->transformedPaths];
        }

        $releasedPaths = array_keys(Arr::dot($released));
        $evidence = ContextReleaseEvidence::make(
            source: $source,
            destination: $destination,
            trust: $trust,
            dataClass: $dataClass,
            permitted: true,
            reason: 'A registered context release policy permitted the projected fields.',
            requestedPaths: $paths,
            releasedPaths: $releasedPaths,
            payloadFingerprint: ArgumentFingerprint::make($released),
            recordedAt: $this->clock->now(),
            transformNames: $transformNames,
            transformedPaths: array_values(array_unique($transformedPaths)),
            invocationId: $this->invocations->current(),
        );
        $this->evidence->recordRelease($evidence);
        $this->recordObservedDerivation($payload, $released, $source, $trust, $dataClass);

        return ContextReleaseResult::permitted($released, $evidence);
    }

    /**
     * Reject a transformer field path that no allowed path can ever match.
     *
     * The comparison is configuration against configuration — declared transformer paths against
     * the release allowlist — never against the realized projection. A path absent from *this*
     * payload (an optional field, a wildcard over an empty collection) is legitimate, and the
     * projector omits absent paths from its output entirely, so comparing against the projection
     * would reject exactly those correct cases.
     *
     * Known limitation: a subtree allowlist such as `only(['user'])` makes every path beneath it
     * reachable, so a typo under that subtree is undetectable here. See docs/security-model.md.
     *
     * @param  list<string>  $allowedPaths
     * @param  list<ContextTransformer>  $transformers
     */
    private function assertTransformerFieldPathsAreReachable(array $allowedPaths, array $transformers): void
    {
        foreach ($transformers as $transformer) {
            if (! $transformer instanceof DeclaresFieldPaths) {
                continue;
            }

            $unreachable = array_values(array_filter(
                $transformer->declaredFieldPaths(),
                fn (string $path): bool => ! $this->isReachable($path, $allowedPaths),
            ));

            if ($unreachable !== []) {
                throw UnreachableTransformerFieldPath::forTransformer($transformer->name(), $unreachable);
            }
        }
    }

    /** @param list<string> $allowedPaths */
    private function isReachable(string $path, array $allowedPaths): bool
    {
        $segments = explode('.', $path);

        foreach ($allowedPaths as $allowed) {
            if ($this->segmentsOverlap($segments, explode('.', $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two paths overlap when one is a segment-wise prefix of the other, treating `*` as any
     * segment. A prefix in either direction counts: an allowlisted subtree covers a deeper
     * transformer path, and a transformer path covering a subtree reaches the allowed leaves
     * beneath it.
     *
     * @param  list<string>  $segments
     * @param  list<string>  $allowedSegments
     */
    private function segmentsOverlap(array $segments, array $allowedSegments): bool
    {
        $shared = min(count($segments), count($allowedSegments));

        for ($index = 0; $index < $shared; $index++) {
            $segment = $segments[$index];
            $allowed = $allowedSegments[$index];

            if ($segment !== $allowed && $segment !== '*' && $allowed !== '*') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $releasedPayload
     */
    private function recordObservedDerivation(
        array $sourcePayload,
        array $releasedPayload,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
    ): void {
        $correlationId = $this->invocations->current();

        if ($correlationId === null) {
            return;
        }

        $parent = $this->provenance->record(
            correlationId: $correlationId,
            source: $source,
            trust: $trust,
            dataClass: $dataClass,
            channel: ContextChannel::ApplicationContext,
            content: $sourcePayload,
        );
        $child = $this->provenance->record(
            correlationId: $correlationId,
            source: $source,
            trust: $trust,
            dataClass: $dataClass,
            channel: ContextChannel::ApplicationContext,
            content: $releasedPayload,
            componentLabel: 'context-release',
        );

        if ($child->contentFingerprint !== $parent->contentFingerprint) {
            $this->provenance->declareDerivation(
                correlationId: $correlationId,
                childContentFingerprint: $child->contentFingerprint,
                parentContentFingerprints: [$parent->contentFingerprint],
                kind: DerivationKind::Transformed,
            );
        }
    }
}
