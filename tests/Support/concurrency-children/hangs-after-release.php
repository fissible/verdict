<?php

declare(strict_types=1);

/**
 * A child that completes the readiness handshake and then hangs instead of finishing its work —
 * the shape #359's T3 is about: `READY_TIMEOUT_SECONDS` guards the handshake, so a child that
 * wedges *after* release was previously bounded by nothing but the CI job-level kill.
 *
 * It sleeps for a bounded number of seconds rather than forever, deliberately. A test proving the
 * absence of a deadline must not itself be able to hang the suite when the deadline is missing:
 * with no timeout the harness returns late and the assertion fails, which is a bounded red.
 */
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$ready = fopen('php://fd/3', 'w');
fwrite($ready, '1');
fclose($ready);

$release = fopen('php://fd/4', 'r');
fread($release, 1);
fclose($release);

$seconds = $payload['hang_seconds'] ?? 8;

if (is_int($seconds) && $seconds > 0) {
    sleep($seconds);
}

fwrite(STDOUT, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
