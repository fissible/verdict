<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use InvalidArgumentException;

final readonly class Destination
{
    private function __construct(
        public string $connection,
        public string $trustZone,
    ) {
        if (trim($this->connection) === '' || trim($this->trustZone) === '') {
            throw new InvalidArgumentException('A context destination must identify a connection and trust zone.');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $this->connection) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $this->trustZone) !== 1) {
            throw new InvalidArgumentException('Context destination identifiers may only contain letters, numbers, dots, underscores, and hyphens.');
        }
    }

    public static function connection(string $name, string $trustZone): self
    {
        return new self($name, $trustZone);
    }

    public function identity(): string
    {
        return "{$this->trustZone}:{$this->connection}";
    }
}
