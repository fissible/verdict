<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * The tool shapes an attack pack can express — the vocabulary of the coverage manifest (#251).
 *
 * Pack versioning makes additions visible; it says nothing about absence. A reader should not
 * need to diff pack versions to learn that no case exercises set-returning tools, so each pack
 * declares the shapes it can express and run output surfaces the declaration — expressible and
 * not-expressible both — beside the existing coverage reporting.
 *
 * The declaration also does double duty (#251 round 3): for a set-returning case, the declared
 * capability shape is the independent source an expected predicate digest derives from, which is
 * what keeps the digest comparison non-tautological — the observed side comes from execution, and
 * the expected side must never be produced by the same scope-building path.
 */
enum ToolShape: string
{
    /** A tool whose argument is one scalar the boundary resolves to a single value. */
    case SingleScalarTarget = 'single-scalar-target';

    /** A tool whose scalar argument resolves to a single record the policy inspects. */
    case RecordKeyed = 'record-keyed';

    /**
     * A set-returning tool: the model supplies a filter, never an ID; the resolver returns an
     * actor-bound scope the policy authorizes; the safe outcome is a filtered permit.
     */
    case SetReturning = 'set-returning';
}
