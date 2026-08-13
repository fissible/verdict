<?php

declare(strict_types=1);

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\PendingContextRelease;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Exceptions\UnreachableTransformerFieldPath;
use Fissible\Verdict\VerdictManager;

beforeEach(function (): void {
    app(VerdictManager::class)->releasePolicy(
        ReleasePolicy::between(redactionSource(), redactionDestination())
            ->allow(DataClass::Sensitive)
            ->whenTrustIs(Trust::Trusted),
    );
});

function redactionSource(): Source
{
    return Source::application('orders');
}

function redactionDestination(): Destination
{
    return Destination::connection('openai', 'model');
}

function releasing(array $payload): PendingContextRelease
{
    return app(VerdictManager::class)->release($payload)
        ->source(redactionSource())
        ->trust(Trust::Trusted)
        ->classify(DataClass::Sensitive);
}

it('rejects a redaction path that is unreachable under the allowlist', function (): void {
    // The operator meant user.socialSecurity. Nothing can ever match user.social_security,
    // so the field it was meant to scrub would be released in full.
    expect(fn (): mixed => releasing(['user' => ['name' => 'Ada', 'socialSecurity' => '123-45-6789']])
        ->only(['user.name', 'user.socialSecurity'])
        ->redact(['user.social_security'])
        ->to(redactionDestination()))
        ->toThrow(UnreachableTransformerFieldPath::class, 'user.social_security');
});

it('accepts a wildcard redaction path that matches no instances', function (): void {
    $result = releasing(['items' => []])
        ->only(['items.*.ssn', 'items.*.label'])
        ->redact(['items.*.ssn'])
        ->to(redactionDestination());

    // An empty collection matches nothing and is legitimate, not a misconfiguration.
    expect($result->permitted)->toBeTrue();
});

it('accepts a redaction path absent from this payload but present in the allowlist', function (): void {
    $result = releasing(['user' => ['name' => 'Ada']])
        ->only(['user.name', 'user.middleName'])
        ->redact(['user.middleName'])
        ->to(redactionDestination());

    // An optional field this record happens to lack is legitimate. Comparing against the
    // realized projection rather than the allowlist would wrongly reject this.
    expect($result->permitted)->toBeTrue();
});

it('still redacts a reachable path', function (): void {
    $result = releasing(['user' => ['name' => 'Ada', 'socialSecurity' => '123-45-6789']])
        ->only(['user.name', 'user.socialSecurity'])
        ->redact(['user.socialSecurity'])
        ->to(redactionDestination());

    expect($result->permitted)->toBeTrue()
        ->and($result->payload['user']['socialSecurity'])->toBe('[REDACTED]')
        ->and($result->payload['user']['name'])->toBe('Ada');
});

/** @verdict-claim limitation.redaction-subtree-allowlist */
it('treats a subtree allowlist as covering everything beneath it', function (): void {
    // The documented limitation: allow('user') makes both spellings reachable, so the typo
    // above is undetectable here. Asserted so the limitation is a decision, not a surprise.
    $result = releasing(['user' => ['name' => 'Ada', 'socialSecurity' => '123-45-6789']])
        ->only(['user'])
        ->redact(['user.social_security'])
        ->to(redactionDestination());

    expect($result->permitted)->toBeTrue()
        ->and($result->payload['user']['socialSecurity'])->toBe('123-45-6789');
});

it('allows an explicit opt-out of field-path validation', function (): void {
    $result = releasing(['user' => ['name' => 'Ada']])
        ->only(['user.name'])
        ->redact(['user.social_security'])
        ->withoutFieldPathValidation()
        ->to(redactionDestination());

    expect($result->permitted)->toBeTrue();
});
