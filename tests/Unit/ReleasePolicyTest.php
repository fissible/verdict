<?php

declare(strict_types=1);

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;

it('deduplicates explicitly allowed classifications and trust levels', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');
    $policy = ReleasePolicy::between($source, $destination)
        ->allow(DataClass::PII, DataClass::PII)
        ->whenTrustIs(Trust::Trusted, Trust::Trusted);

    expect($policy->permits($source, $destination, DataClass::PII, Trust::Trusted))->toBeTrue()
        ->and($policy->permits($source, $destination, DataClass::PII, Trust::Untrusted))->toBeFalse();
});

it('rejects ambiguous source and destination identifiers', function (Closure $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'source separator' => [fn (): Source => Source::application('customer:profile')],
    'destination separator' => [fn (): Destination => Destination::connection('ollama:local', 'local-machine')],
    'trust-zone separator' => [fn (): Destination => Destination::connection('ollama-local', 'local:machine')],
]);

it('rejects duplicate policies for the same exact route', function (): void {
    $source = Source::application('customer-profile');
    $destination = Destination::connection('ollama-local', 'local-machine');
    $registry = new ReleasePolicyRegistry;
    $policy = ReleasePolicy::between($source, $destination)
        ->allow(DataClass::PII)
        ->whenTrustIs(Trust::Trusted);

    $registry->register($policy);

    expect(fn (): ReleasePolicyRegistry => $registry->register($policy))
        ->toThrow(LogicException::class);
});
