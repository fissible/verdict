<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextTransformationResult;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ContextTransformer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Facades\Verdict;
use Fissible\Verdict\Actions\InvocationContext;

/** @verdict-claim security.context-release */
it('releases only allowlisted fields over an explicitly permitted route', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');

    Verdict::releasePolicy(
        ReleasePolicy::between($source, $destination)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    $result = Verdict::release([
        'first_name' => 'Avery',
        'email' => 'avery@example.com',
        'ssn' => '111-22-3333',
        'orders' => [
            ['number' => 1002, 'status' => 'processing', 'payment_token' => 'secret'],
        ],
    ])
        ->source($source)
        ->trust(Trust::Trusted)
        ->classify(DataClass::PII)
        ->only(['first_name', 'orders.*.number', 'orders.*.status'])
        ->to($destination);

    expect($result->permitted)->toBeTrue()
        ->and($result->payload)->toBe([
            'first_name' => 'Avery',
            'orders' => [
                ['number' => 1002, 'status' => 'processing'],
            ],
        ])
        ->and($result->payload)->not->toHaveKey('email')
        ->and($result->payload)->not->toHaveKey('ssn');

    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $evidence = $recorder->releases()[0];
    $serializedEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);

    expect($evidence->disposition)->toBe('permit')
        ->and($evidence->source)->toBe('application:customer-profile')
        ->and($evidence->destination)->toBe('local-machine:ollama-local')
        ->and($evidence->releasedPathFingerprints)->toHaveCount(3)
        ->and($evidence->payloadFingerprint)->not->toBeNull()
        ->and($evidence->invocationId)->toBeNull()
        ->and($serializedEvidence)->not->toContain('Avery')
        ->and($serializedEvidence)->not->toContain('avery@example.com')
        ->and($serializedEvidence)->not->toContain('111-22-3333');
});

it('fails closed when no release policy permits the exact destination route', function (): void {
    $source = Source::application('customer-profile');
    $local = Destination::connection('ollama-local', 'local-machine');
    $remote = Destination::connection('ollama-local', 'remote-network');

    Verdict::releasePolicy(
        ReleasePolicy::between($source, $local)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    $result = Verdict::release([
        'first_name' => 'Avery',
        'ssn' => '111-22-3333',
    ])
        ->source($source)
        ->trust(Trust::Trusted)
        ->classify(DataClass::PII)
        ->only(['first_name'])
        ->to($remote);

    expect($result->permitted)->toBeFalse()
        ->and($result->payload)->toBe([])
        ->and($result->evidence->disposition)->toBe('deny')
        ->and($result->evidence->releasedPathFingerprints)->toBe([])
        ->and($result->evidence->payloadFingerprint)->toBeNull();
});

it('records an observed source-to-release derivation within a Laravel AI invocation', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');
    Verdict::releasePolicy(
        ReleasePolicy::between($source, $destination)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    app(InvocationContext::class)->within('invocation-release', function () use ($source, $destination): void {
        Verdict::release(['first_name' => 'Avery', 'ssn' => '111-22-3333'])
            ->source($source)
            ->trust(Trust::Trusted)
            ->classify(DataClass::PII)
            ->only(['first_name'])
            ->to($destination);
    });

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('invocation-release');
    expect($entries)->toHaveCount(2);

    $derivations = $recorder->derivationsFor('invocation-release', $entries[1]->contentFingerprint);
    expect($derivations)->toHaveCount(1)
        ->and($derivations[0]->parentContentFingerprint)->toBe($entries[0]->contentFingerprint);
});

it('redacts only explicitly projected structured fields and records redacted transform evidence', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');

    Verdict::releasePolicy(
        ReleasePolicy::between($source, $destination)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    $result = Verdict::release([
        'first_name' => 'Avery',
        'email' => 'avery@example.com',
        'ssn' => '111-22-3333',
    ])
        ->source($source)
        ->trust(Trust::Trusted)
        ->classify(DataClass::PII)
        ->only(['first_name', 'email'])
        ->redact(['email'])
        ->to($destination);

    expect($result->payload)->toBe([
        'first_name' => 'Avery',
        'email' => '[REDACTED]',
    ])->and($result->payload)->not->toHaveKey('ssn')
        ->and($result->evidence->transformFingerprints)->toBe([
            hash('sha256', 'structured_redaction'),
        ])->and($result->evidence->transformedPathFingerprints)->toBe([
            hash('sha256', 'email'),
        ])->and(json_encode($result->evidence, JSON_THROW_ON_ERROR))
        ->not->toContain('avery@example.com')
        ->not->toContain('email');
});

it('stops a custom transformer from expanding the explicit field projection', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');
    $expander = new class implements ContextTransformer
    {
        public function name(): string
        {
            return 'unsafe_expander';
        }

        public function transform(array $payload): ContextTransformationResult
        {
            return new ContextTransformationResult(
                payload: [...$payload, 'ssn' => '111-22-3333'],
                transformedPaths: ['ssn'],
            );
        }
    };

    Verdict::releasePolicy(
        ReleasePolicy::between($source, $destination)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    expect(fn () => Verdict::release(['first_name' => 'Avery'])
        ->source($source)
        ->trust(Trust::Trusted)
        ->classify(DataClass::PII)
        ->only(['first_name'])
        ->through($expander)
        ->to($destination))->toThrow(
            LogicException::class,
            'must not expand the explicitly projected field set',
        );
});

it('does not let an allowed route silently expand to another trust level or data class', function (
    Trust $trust,
    DataClass $dataClass,
): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');

    Verdict::releasePolicy(
        ReleasePolicy::between($source, $destination)
            ->allow(DataClass::PII)
            ->whenTrustIs(Trust::Trusted),
    );

    $result = Verdict::release(['first_name' => 'Avery'])
        ->source($source)
        ->trust($trust)
        ->classify($dataClass)
        ->only(['first_name'])
        ->to($destination);

    expect($result->permitted)->toBeFalse()
        ->and($result->payload)->toBe([]);
})->with([
    'untrusted PII' => [Trust::Untrusted, DataClass::PII],
    'trusted sensitive data' => [Trust::Trusted, DataClass::Sensitive],
]);

it('does not release when required labels or an allowlist are omitted', function (Closure $release): void {
    expect($release)->toThrow(LogicException::class);
})->with([
    'missing source trust and classification' => [
        fn () => Verdict::release(['first_name' => 'Avery'])
            ->only(['first_name'])
            ->to(Destination::connection('ollama-local', 'local-machine')),
    ],
    'missing field allowlist' => [
        function () {
            $source = Source::application('customer-profile');
            $destination = Destination::connection('ollama-local', 'local-machine');

            Verdict::releasePolicy(
                ReleasePolicy::between($source, $destination)
                    ->allow(DataClass::PII)
                    ->whenTrustIs(Trust::Trusted),
            );

            return Verdict::release(['first_name' => 'Avery'])
                ->source($source)
                ->trust(Trust::Trusted)
                ->classify(DataClass::PII)
                ->to($destination);
        },
    ],
]);
