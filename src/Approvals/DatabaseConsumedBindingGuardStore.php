<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class DatabaseConsumedBindingGuardStore implements ConsumedBindingGuardStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
    ) {}

    public function lookup(string $digest): ?DerivedGuard
    {
        // Illuminate binds streams as PDO::PARAM_LOB; the stream contains the raw bytes.
        $binary = fopen('data://text/plain;base64,'.base64_encode($digest), 'rb');

        if ($binary === false) {
            throw new RuntimeException('Unable to open the consumed-binding digest stream.');
        }

        try {
            // Match by the binary digest and read only the scheme metadata: the digest we return is
            // the known argument, so the bytea column need not be read back (it is a stream resource).
            $row = $this->connection->table($this->table)
                ->where('digest', $binary)
                ->first(['algorithm', 'key_version']);
        } finally {
            fclose($binary);
        }

        if ($row === null) {
            return null;
        }

        return new DerivedGuard(
            $digest,
            $row->algorithm === null ? null : (string) $row->algorithm,
            $row->key_version === null ? null : (string) $row->key_version,
        );
    }

    public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
    {
        // Illuminate binds streams as PDO::PARAM_LOB; the stream contains the raw bytes.
        $binary = fopen('data://text/plain;base64,'.base64_encode($digest), 'rb');

        if ($binary === false) {
            throw new RuntimeException('Unable to open the consumed-binding digest stream.');
        }

        try {
            $this->connection->table($this->table)->insertOrIgnore([
                'digest' => $binary,
                'consumed_at' => DateTimeImmutable::createFromInterface($consumedAt)
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'algorithm' => $algorithm,
                'key_version' => $keyVersion,
            ]);
        } finally {
            fclose($binary);
        }
    }

    public function hasSchemedGuard(): bool
    {
        return $this->connection->table($this->table)
            ->whereNotNull('algorithm')
            ->orWhereNotNull('key_version')
            ->exists();
    }
}
