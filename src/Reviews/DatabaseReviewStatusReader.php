<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Fissible\Verdict\Contracts\ReviewStatusReader;

/**
 * The reader paired with DatabaseReviewRequestStore (ADR 0035 §4), on the store's own connection,
 * and the approvals precedent one lane over — see DatabaseApprovalStatusReader, whose shape this
 * follows deliberately rather than inventing a second answer to the same question.
 *
 * Status reads ride the store's find(). Enumeration discovers candidates with a portable query —
 * persisted status Pending and a stored approval_context worth matching — applies the typed
 * containment of ADR 0035 §4 in PHP on the decoded context, and hydrates only the matches through
 * the store's bulk findMany() in one read per 1,000 matches. The store remains the single
 * row-mapping authority; no backend JSON containment operator, or any backend's number/string
 * coercion, is involved (#327's portability decision).
 *
 * The contractual order is imposed in PHP, not by the candidate query, and that is deliberate.
 * `ORDER BY id` sorts under the connection's collation, and MySQL's default collation is
 * case-insensitive: for two ids created in the same second, 'Zx…' and 'ax…' — both ordinary
 * Str::random(64) output — come back 'a' first from MySQL and 'Z' first from a byte comparison. A
 * reader whose order depends on the engine is not the single order ADR 0035 §4 defines, and the
 * divergence would be invisible to any fixture that happens to use one case.
 *
 * On an install that has not run the approval_context migration, no request has a context, so
 * enumeration honestly returns nothing. The column's presence is memoized per store instance, so a
 * long-lived worker that ran before the migration must be restarted after it — the standard
 * worker-restart obligation for any deploy-time schema change. Note that verdict:validate does not
 * yet audit the review store's columns the way it audits the approval store's (#488), so this
 * degradation is currently silent to an operator.
 */
final readonly class DatabaseReviewStatusReader implements ReviewStatusReader
{
    public function __construct(
        private DatabaseReviewRequestStore $store,
    ) {}

    public function statusFor(string $requestId): ?ReviewStatusView
    {
        return ReviewStatusView::fromNullableRequest($this->store->find($requestId));
    }

    public function pendingWithin(array $scope): array
    {
        ReviewScopeMatch::assertScope($scope);

        if (! $this->store->hasApprovalContextColumn()) {
            return [];
        }

        // '[]' is a real stored value — a context captured empty — and can never match a non-empty
        // scope, so it is excluded alongside NULL before any row leaves the database.
        $candidates = $this->store->connection()->table($this->store->table())
            ->where('status', ReviewStatus::Pending->value)
            ->whereNotNull('approval_context')
            ->where('approval_context', '!=', '[]')
            ->get(['id', 'approval_context']);

        $matchedIds = [];

        foreach ($candidates as $row) {
            if (! is_string($row->id) || ! is_string($row->approval_context)) {
                continue;
            }

            $context = json_decode($row->approval_context, true);

            if (! is_array($context) || ! ReviewScopeMatch::matches($context, $scope)) {
                continue;
            }

            $matchedIds[] = $row->id;
        }

        $requests = $this->store->findMany($matchedIds);
        $matches = [];

        foreach ($matchedIds as $requestId) {
            $request = $requests[$requestId] ?? null;

            // Re-checked after hydration: a transition committed between the candidate query and
            // the hydration is poll-consistency at work, not an error — the resolved request simply
            // no longer enumerates.
            if ($request === null || $request->status !== ReviewStatus::Pending) {
                continue;
            }

            $matches[] = $request;
        }

        // createdAt at the precision the write path persists, then the request id, compared as text
        // so neither a collation nor PHP's numeric-string comparison can reorder them.
        usort($matches, static fn (ReviewRequest $a, ReviewRequest $b): int => strcmp(
            $a->createdAt->format('Y-m-d H:i:s'),
            $b->createdAt->format('Y-m-d H:i:s'),
        ) ?: strcmp($a->id, $b->id));

        return array_map(
            static fn (ReviewRequest $request): ReviewStatusView => ReviewStatusView::fromRequest($request),
            $matches,
        );
    }
}
