<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\CanonicalJson;
use Fissible\Verdict\Evidence\ContentFingerprint;

/**
 * A structure exercising every part of the contract: key ordering, nesting, list order, a float, a
 * zero-fraction float, null, and a string.
 *
 * @return array<string, mixed>
 */
function bindingContractFixture(): array
{
    return ['b' => 2, 'a' => 1, 'nested' => ['z' => [1, 2], 'y' => true], 'f' => 0.1, 's' => 'x', 'n' => null, 'z' => 1.0];
}

afterEach(function (): void {
    ini_set('serialize_precision', '-1');
});

/**
 * The digest of a fixed structure is a persisted value: approval receipts and execution claims hold
 * it, and a change to canonicalization silently stops those from matching the requests they
 * authorized. This pins the value so any such change breaks here first.
 *
 * If this fails, canonicalization changed. That is not necessarily wrong — but it invalidates every
 * persisted receipt and open claim whose binding contains one of these shapes, so it needs an
 * upgrade note, not a new expected digest.
 */
it('pins the canonical digest of a fixed structure', function (): void {
    expect(ArgumentFingerprint::make(bindingContractFixture()))
        ->toBe('c0fc1ae4e195fc450450af26864a61d9d751b6b4684fb42a3aba5860c63d20c2')
        ->and(ArgumentFingerprint::canonicalJson(bindingContractFixture()))
        ->toBe('{"a":1,"b":2,"f":0.1,"n":null,"nested":{"y":true,"z":[1,2]},"s":"x","z":1.0}');
});

it('agrees between the binding and content primitives for the same structure', function (): void {
    expect(ContentFingerprint::make(bindingContractFixture()))
        ->toBe(ArgumentFingerprint::make(bindingContractFixture()));
});

/**
 * json_encode renders floats according to serialize_precision, so without pinning, two deployments
 * — or one deployment either side of an ini change — fingerprint the same float differently. An
 * approval issued before the change then cannot be consumed after it: fail-closed, and baffling.
 */
it('fingerprints a float identically under any serialize_precision', function (): void {
    ini_set('serialize_precision', '17');
    $atSeventeen = ArgumentFingerprint::make(bindingContractFixture());
    $contentAtSeventeen = ContentFingerprint::make(bindingContractFixture());

    ini_set('serialize_precision', '-1');
    $atDefault = ArgumentFingerprint::make(bindingContractFixture());

    expect($atSeventeen)->toBe($atDefault)
        ->and($contentAtSeventeen)->toBe($atDefault)
        ->and($atSeventeen)->toBe('c0fc1ae4e195fc450450af26864a61d9d751b6b4684fb42a3aba5860c63d20c2');
});

it('keeps PHP default float tokens under arbitrary process precision', function (): void {
    $fixture = [
        'tenth' => 0.1,
        'fraction' => 1.0,
        'small' => 1.0e-6,
        'large' => 1.0e20,
        'negative_zero' => -0.0,
    ];

    foreach (['17', '-1', '3', '0'] as $precision) {
        ini_set('serialize_precision', $precision);

        expect(CanonicalJson::encode($fixture, 'float compatibility fixture'))
            ->toBe('{"fraction":1.0,"large":1.0e+20,"negative_zero":-0.0,"small":1.0e-6,"tenth":0.1}');
    }
});

it('does not alter a caller-chosen serialize_precision while fingerprinting', function (): void {
    ini_set('serialize_precision', '17');

    ArgumentFingerprint::make(bindingContractFixture());

    expect(ini_get('serialize_precision'))->toBe('17');
});

/**
 * An object reaching json_encode is fingerprinted by whatever it happens to emit: JsonSerializable
 * puts an application-defined method inside the binding computation, private and protected
 * properties are silently dropped, and `(object) ['a' => 1]` collides with `['a' => 1]` — a
 * different PHP type treated as the same authorized request.
 */
it('refuses a value it cannot canonicalize', function (callable $fingerprint): void {
    expect($fingerprint)->toThrow(InvalidArgumentException::class);
})->with([
    'binding object' => [fn (): string => ArgumentFingerprint::make(['payload' => (object) ['a' => 1]])],
    'binding nested object' => [fn (): string => ArgumentFingerprint::make(['deep' => ['payload' => (object) ['a' => 1]]])],
    'binding json string' => [fn (): string => ArgumentFingerprint::canonicalJson(['payload' => (object) ['a' => 1]])],
    'binding json-serializable' => [fn (): string => ArgumentFingerprint::make(['payload' => new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['a' => 1];
        }
    }])],
    'content object' => [fn (): string => ContentFingerprint::make(['payload' => (object) ['a' => 1]])],
    'content top-level object' => [fn (): string => ContentFingerprint::make(new stdClass)],
]);

it('names the offending type when it refuses a value', function (): void {
    expect(fn (): string => ArgumentFingerprint::make(['payload' => (object) ['a' => 1]]))
        ->toThrow(InvalidArgumentException::class, 'stdClass');
});

it('no longer treats an object as the same request as an array of the same shape', function (): void {
    $asArray = ArgumentFingerprint::make(['payload' => ['a' => 1]]);

    expect($asArray)->toBeString()
        ->and(fn (): string => ArgumentFingerprint::make(['payload' => (object) ['a' => 1]]))
        ->toThrow(InvalidArgumentException::class);
});
