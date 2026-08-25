<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Support\SecurityStateTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use stdClass;

final readonly class DatabaseActionIntentStore implements ActionIntentStore, DatabaseTableStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'verdict_action_intents',
    ) {}

    public function hasTable(): bool
    {
        if (! $this->connection instanceof Connection) {
            throw new LogicException('The action-intent connection does not support schema inspection.');
        }

        return $this->connection->getSchemaBuilder()->hasTable($this->table);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function record(ActionIntent $intent): void
    {
        SecurityStateTransaction::run($this->connection, 'record an action intent', function () use ($intent): void {
            $this->connection->table($this->table)->insert([
                'id' => $intent->id,
                'capability' => $intent->capability,
                'configuration_fingerprint' => $intent->configurationFingerprint,
                'actor_fingerprint' => $intent->actorFingerprint,
                'subject_fingerprint' => $intent->subjectFingerprint,
                'execution_target_identity_fingerprint' => $intent->executionTargetIdentityFingerprint,
                'argument_fingerprint' => $intent->argumentFingerprint,
                'invocation_id' => $intent->invocationId,
                'recorded_at' => $intent->recordedAt,
            ]);
        });
    }

    public function find(string $id): ?ActionIntent
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        return $row instanceof stdClass ? $this->intentFromRow($row) : null;
    }

    private function intentFromRow(stdClass $row): ActionIntent
    {
        return new ActionIntent(
            id: (string) $row->id,
            capability: (string) $row->capability,
            configurationFingerprint: (string) $row->configuration_fingerprint,
            actorFingerprint: $row->actor_fingerprint === null ? null : (string) $row->actor_fingerprint,
            subjectFingerprint: $row->subject_fingerprint === null ? null : (string) $row->subject_fingerprint,
            executionTargetIdentityFingerprint: $row->execution_target_identity_fingerprint === null
                ? null
                : (string) $row->execution_target_identity_fingerprint,
            argumentFingerprint: (string) $row->argument_fingerprint,
            invocationId: $row->invocation_id === null ? null : (string) $row->invocation_id,
            recordedAt: new DateTimeImmutable((string) $row->recorded_at, new DateTimeZone('UTC')),
        );
    }
}
