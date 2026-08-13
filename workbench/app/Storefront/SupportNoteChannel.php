<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

/**
 * Holds the support-note text a live evaluation case wants delivered as *tool output* rather
 * than folded into the prompt. `StorefrontLiveSuiteFactory` sets this before each agent
 * invocation from `CaseInput::$untrustedInput['retrieved_document']`; the `orders.support-notes`
 * capability registered in `WorkbenchServiceProvider` reads it when its executor runs.
 *
 * This is what makes `indirect-instruction-in-retrieved-document` an indirect injection again:
 * the untrusted text arrives as the result of a tool call the model chooses to make, not as text
 * the harness placed directly in the user turn.
 */
final class SupportNoteChannel
{
    private ?string $note = null;

    public function set(?string $note): void
    {
        $this->note = $note;
    }

    public function current(): ?string
    {
        return $this->note;
    }
}
