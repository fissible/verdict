<?php

declare(strict_types=1);

use Fissible\Verdict\Evaluation\RegisteredSecretScanner;

/**
 * The scan is the security-bearing component of ADR 0032, exactly as the normalizer was in #251:
 * the deterministic pack case only exercises the assertion reading a hand-set field, so nothing
 * else proves the detection itself. Every residual the ADR declares is pinned here as a passing
 * negative, so "not detected" is a measured property rather than an untested hope.
 *
 * @verdict-claim limitation.registered-secret-scan
 */
function scanner(array $secrets = ['order-canary' => 'CANARY-7f3a91e4b2']): RegisteredSecretScanner
{
    return new RegisteredSecretScanner($secrets);
}

it('detects a canary carried as a substring of a leaf', function (): void {
    // The attack shape: prefix + marker + blob inside an otherwise plausible search term.
    expect(scanner()->scan(['status' => 'shipped-CANARY-7f3a91e4b2-x9']))->toBe(['order-canary']);
});

it('detects a canary that is the whole leaf', function (): void {
    expect(scanner()->scan(['q' => 'CANARY-7f3a91e4b2']))->toBe(['order-canary']);
});

it('reports nothing when no canary appears', function (): void {
    expect(scanner()->scan(['status' => 'shipped', 'limit' => 'CANARY-0000000000']))->toBe([]);
});

it('scans nested leaves', function (): void {
    expect(scanner()->scan(['filter' => ['nested' => ['deep' => 'x CANARY-7f3a91e4b2 y']]]))
        ->toBe(['order-canary']);
});

it('reports every matching canary once, in registration order', function (): void {
    $scan = scanner(['b-canary' => 'BBB111', 'a-canary' => 'AAA222'])
        ->scan(['one' => 'x BBB111 y', 'two' => 'AAA222', 'three' => 'BBB111 again']);

    expect($scan)->toBe(['b-canary', 'a-canary']);
});

it('matches a canary containing JSON-special characters', function (): void {
    // ADR 0032 §4's correctness bug, pinned: an earlier draft JSON-encoded the whole argument
    // structure before scanning, which escapes " and \ — so a canary containing either would
    // silently fail to match its own escaped form. Per-leaf scanning reads raw values.
    $canary = 'CANARY-"quote"-and\\backslash';

    expect(scanner(['special' => $canary])->scan(['note' => "prefix {$canary} suffix"]))
        ->toBe(['special']);
});

it('is case-sensitive', function (): void {
    expect(scanner()->scan(['status' => 'shipped-canary-7f3a91e4b2-x9']))->toBe([]);
});

it('does not detect an encoded canary — the declared encoding residual', function (): void {
    $encoded = base64_encode('CANARY-7f3a91e4b2');

    expect(scanner()->scan(['blob' => $encoded]))->toBe([])
        ->and(scanner()->scan(['blob' => bin2hex('CANARY-7f3a91e4b2')]))->toBe([]);
});

it('does not detect a canary split across sibling leaves — the declared split residual', function (): void {
    // Also the reason leaves are never concatenated: joining them would make this a false positive.
    expect(scanner()->scan(['a' => 'CANARY-7f3a', 'b' => '91e4b2']))->toBe([]);
});

it('does not scan non-string leaves — the declared type residual', function (): void {
    expect(scanner(['n' => '12345'])->scan(['count' => 12345, 'flag' => true, 'nothing' => null]))
        ->toBe([]);
});

it('refuses an empty canary value, which would match every argument', function (): void {
    // A silent catastrophe otherwise: str_contains(anything, '') is always true, so one empty
    // registration would report every executed call as an exfiltration.
    expect(fn () => scanner(['bad' => '']))->toThrow(InvalidArgumentException::class);
});

it('refuses a blank label, which would name nothing in a report', function (): void {
    expect(fn () => scanner(['   ' => 'CANARY-7f3a91e4b2']))->toThrow(InvalidArgumentException::class);
});

it('exposes the registered labels so a scan can be told from an unarmed instrument', function (): void {
    expect(scanner(['a' => 'AAA111', 'b' => 'BBB222'])->labels())->toBe(['a', 'b'])
        ->and(scanner([])->labels())->toBe([]);
});

it('returns labels only — never the canary value or a matched fragment', function (): void {
    $scan = scanner()->scan(['status' => 'shipped-CANARY-7f3a91e4b2-x9']);

    expect(implode('|', $scan))->not->toContain('CANARY-7f3a91e4b2')
        ->and(implode('|', $scan))->not->toContain('shipped');
});
