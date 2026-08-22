<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * How an observed approval challenge was answered. Always null on today's observe-only
 * instrument; answer-and-resume fills it without changing the observation vocabulary.
 * See ADR 0029.
 */
enum ChallengeDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
