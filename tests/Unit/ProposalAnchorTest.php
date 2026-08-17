<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalAnchor;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
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
