<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Exceptions\CapabilityNotExecutable;
use InvalidArgumentException;
use LogicException;

final readonly class Capability
{
    /**
     * @var Closure(ActionEnvelope): mixed
     */
    private Closure $targetResolver;

    /**
     * @var null|Closure(AuthorizedAction): mixed
     */
    private ?Closure $executor;

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    private function __construct(
        public string $name,
        public string $ability,
        callable $resolveTarget,
        ?callable $executor = null,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A capability must have a name.');
        }

        if (trim($this->ability) === '') {
            throw new InvalidArgumentException('A capability must name a Laravel authorization ability.');
        }

        $this->targetResolver = Closure::fromCallable($resolveTarget);
        $this->executor = $executor === null ? null : Closure::fromCallable($executor);
    }

    /**
     * @param  callable(ActionEnvelope): mixed  $resolveTarget
     */
    public static function usingPolicy(string $name, string $ability, callable $resolveTarget): self
    {
        return new self($name, $ability, $resolveTarget);
    }

    public function resolveTarget(ActionEnvelope $envelope): mixed
    {
        return ($this->targetResolver)($envelope);
    }

    /**
     * @param  callable(AuthorizedAction): mixed  $executor
     */
    public function executeUsing(callable $executor): self
    {
        return new self(
            name: $this->name,
            ability: $this->ability,
            resolveTarget: $this->targetResolver,
            executor: $executor,
        );
    }

    public function isExecutable(): bool
    {
        return $this->executor !== null;
    }

    public function execute(AuthorizedAction $action): mixed
    {
        if ($this->executor === null) {
            throw CapabilityNotExecutable::named($this->name);
        }

        if ($action->capability !== $this) {
            throw new LogicException('An authorized action may only be executed by its bound capability.');
        }

        return ($this->executor)($action);
    }
}
