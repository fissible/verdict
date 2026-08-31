<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * Marker, no methods: an ApprovalReceiptStore declares that approve()/reject() refuse any receipt
 * that is not call-matching, Pending, and unexpired at the supplied instant.
 */
interface EnforcesDecisionAdmissibility {}
