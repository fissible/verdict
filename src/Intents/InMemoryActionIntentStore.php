<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use Fissible\Verdict\Contracts\ActionIntentStore;
use LogicException;

/**
 * Only for tests and local development: process-local intent state cannot make the durability
 * promise the intent lever exists to give a compliance deployment.
 */
final class InMemoryActionIntentStore implements ActionIntentStore
{
    /** @var array<string, ActionIntent> */
    private array $intents = [];

    public function record(ActionIntent $intent): void
    {
        if (array_key_exists($intent->id, $this->intents)) {
            throw new LogicException("An intent record with id [{$intent->id}] already exists; intents are write-once.");
        }

        $this->intents[$intent->id] = $intent;
    }

    public function find(string $id): ?ActionIntent
    {
        return $this->intents[$id] ?? null;
    }
}
