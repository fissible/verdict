<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Fissible\Verdict\Contracts\ChainGapReader;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class DatabaseChainGapReader implements ChainGapReader
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'verdict_evidence',
    ) {}

    public function gapsForChain(string $chainId): ChainGapSummary
    {
        $count = 0;
        $latestMarkAt = null;

        foreach ($this->connection->table($this->table)->select(['reason', 'recorded_at'])->where('record_type', 'chain_gap')->get() as $row) {
            if (! is_string($row->reason ?? null)) {
                continue;
            }

            $reason = json_decode($row->reason, true);

            if (! is_array($reason) || ($reason['chain'] ?? null) !== $chainId) {
                continue;
            }

            $count++;
            $recordedAt = $this->utcDateTime($row->recorded_at ?? null);

            if ($recordedAt !== null && ($latestMarkAt === null || $recordedAt > $latestMarkAt)) {
                $latestMarkAt = $recordedAt;
            }
        }

        return $count === 0 ? ChainGapSummary::none() : new ChainGapSummary($count, $latestMarkAt);
    }

    private function utcDateTime(mixed $value): ?DateTimeImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
            }

            return is_string($value)
                ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
