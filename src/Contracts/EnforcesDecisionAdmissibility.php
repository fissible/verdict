<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * Marker, no methods: an ApprovalReceiptStore declares that approve()/reject() atomically refuse
 * every receipt that is not call-matching, Pending, and unexpired at the supplied instant, with
 * the applicable canonical failure outcome. ApprovalManager may therefore delegate a found,
 * call-matching inadmissible receipt without first consulting its decision authorizer.
 *
 * Verdict cannot verify this promise. A decorator must re-declare this marker only when it
 * preserves the guarantee; otherwise it falls back to the safe pre-#320 authorization path and
 * does not inherit the wrapped store's declaration.
 */
interface EnforcesDecisionAdmissibility {}
