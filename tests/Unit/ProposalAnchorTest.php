<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalAnchor;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;

function proposalAnchorLedger(InMemoryEvidenceRecorder $recorder): ProvenanceLedger
{
    return new ProvenanceLedger($recorder, $recorder, new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
        }
    });
}

it('anchors a proposal on the fingerprint the ledger records for the same arguments', function (): void {
    $arguments = ['order_id' => 7, 'amount' => 250.0, 'reason' => 'duplicate charge'];
    $recorder = new InMemoryEvidenceRecorder;

    $recorded = proposalAnchorLedger($recorder)->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: $arguments,
    );

    expect(ProposalAnchor::for($arguments))->toBe(
        $recorded->contentFingerprint,
        'ProposalAnchor must follow the ledger digest. These agree today because ArgumentFingerprint '
        .'and ContentFingerprint share a normalization, which is a coincidence this test converts into '
        .'a contract. If this fails, either restore the shared canonicalization or split the anchor '
        .'deliberately — do not relax the assertion. A declaration made against an anchor the ledger '
        .'does not recognise is unreachable by construction: it never errors, it silently never '
        .'matches, and every approver is told the proposal origin is unknown.',
    );
});

it('anchors identical arguments identically regardless of key order', function (): void {
    expect(ProposalAnchor::for(['amount' => 250.0, 'order_id' => 7]))
        ->toBe(ProposalAnchor::for(['order_id' => 7, 'amount' => 250.0]));
});

it('distinguishes arguments that differ only in value type', function (): void {
    expect(ProposalAnchor::for(['amount' => 250]))
        ->not->toBe(ProposalAnchor::for(['amount' => 250.0]));
});

/**
 * The join `docs/incident-response.md` Step 4 documents runs from a decision row's
 * `argument_fingerprint` — which is `ArgumentFingerprint::make()`, not `ProposalAnchor::for()` — into
 * `verdict_provenance_derivations.child_content_fingerprint`. The test above pins the anchor to the ledger
 * digest but never exercises `ArgumentFingerprint`, so that documented query rests on nothing. This pins it.
 *
 * @verdict-claim evidence.argument-fingerprint-anchors-provenance
 */
it('fingerprints a decision argument identically to the proposal anchor the ledger indexes', function (): void {
    $arguments = ['order_id' => 7, 'amount' => 250.0, 'reason' => 'duplicate charge'];

    expect(ArgumentFingerprint::make($arguments))->toBe(
        ProposalAnchor::for($arguments),
        'A decision row records ArgumentFingerprint::make() in argument_fingerprint, and an incident '
        .'reconstruction uses that value as a child_content_fingerprint. If these diverge, the documented '
        .'join returns no rows rather than erroring, and every reconstruction reports the proposal as '
        .'having no declared upstream. Restore the shared canonicalization, or update '
        .'docs/incident-response.md to stop documenting the join.',
    );
});
