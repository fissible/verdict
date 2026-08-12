<?php

declare(strict_types=1);

namespace Fissible\Verdict\LaravelAi;

use Closure;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Evidence\ProvenanceEntry;

/**
 * Tracks the Laravel AI invocation currently in scope.
 *
 * Provenance entries are correlated by Laravel AI's per-generation invocation id. Decisions recorded
 * during that generation need the same id, and requiring the application to thread it through every
 * `runBound()` call would mean the correlation is missing exactly when an incident makes it matter.
 *
 * Frames are a stack rather than a single value because a tool may start a nested generation while it
 * runs — `AgentTool` does this by design when it prompts a sub-agent. Nesting unwinds strictly LIFO,
 * so the innermost frame is always the invocation a decision belongs to.
 *
 * Outside any Laravel AI invocation — a queue job, a controller, a test — there is no frame and
 * {@see self::current()} returns `null`. That `null` means "no invocation context," not "lost."
 */
final class InvocationContext
{
    /** @var list<string> */
    private array $frames = [];

    /** @var array<string, array<string, array{arguments: array<string, mixed>, envelope: ActionEnvelope}>> */
    private array $preparedEnvelopes = [];

    /**
     * The invocation currently in scope, or null when not inside one.
     */
    public function current(): ?string
    {
        $frame = end($this->frames);

        return $frame === false ? null : $frame;
    }

    /**
     * Run the callback with the given invocation in scope.
     */
    public function within(string $invocationId, Closure $callback): mixed
    {
        $this->push($invocationId);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    /**
     * Push an invocation frame for a lazy streamed response.
     */
    public function push(string $invocationId): void
    {
        ProvenanceEntry::assertIdentifier($invocationId, 'Provenance correlation');

        $this->frames[] = $invocationId;
    }

    /**
     * Pop the most recently pushed invocation frame.
     */
    public function pop(): void
    {
        $invocationId = array_pop($this->frames);

        if ($invocationId !== null && ! in_array($invocationId, $this->frames, true)) {
            unset($this->preparedEnvelopes[$invocationId]);
        }
    }

    /** @param array<string, mixed> $arguments */
    public function rememberPreparedEnvelope(string $toolCallId, array $arguments, ActionEnvelope $envelope): void
    {
        $invocationId = $this->current();

        if ($invocationId === null || blank($toolCallId)) {
            return;
        }

        $this->preparedEnvelopes[$invocationId][$toolCallId] = compact('arguments', 'envelope');
    }

    /** @param array<string, mixed> $arguments */
    public function takePreparedEnvelope(string $toolCallId, array $arguments): ?ActionEnvelope
    {
        $invocationId = $this->current();

        if ($invocationId === null || blank($toolCallId)) {
            return null;
        }

        $prepared = $this->preparedEnvelopes[$invocationId][$toolCallId] ?? null;
        unset($this->preparedEnvelopes[$invocationId][$toolCallId]);

        if (($this->preparedEnvelopes[$invocationId] ?? []) === []) {
            unset($this->preparedEnvelopes[$invocationId]);
        }

        return $prepared !== null && $prepared['arguments'] === $arguments
            ? $prepared['envelope']
            : null;
    }
}
