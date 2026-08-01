<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use InvalidArgumentException;

final readonly class Source
{
    private function __construct(
        public string $kind,
        public string $name,
    ) {
        if (trim($this->kind) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('A context source must have a kind and name.');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $this->kind) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Context source identifiers may only contain letters, numbers, dots, underscores, and hyphens.');
        }
    }

    public static function application(string $name): self
    {
        return new self('application', $name);
    }

    public static function user(string $name): self
    {
        return new self('user', $name);
    }

    public static function external(string $name): self
    {
        return new self('external', $name);
    }

    public function identity(): string
    {
        return "{$this->kind}:{$this->name}";
    }
}
