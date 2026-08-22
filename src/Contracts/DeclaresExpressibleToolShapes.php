<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\ToolShape;

/**
 * An attack pack's machine-readable coverage manifest (#251): the tool shapes its cases can
 * express. Suite builders pass the declaration into `SecuritySuite`, and reports surface it —
 * expressible and not-expressible both — so absence is stated rather than discovered by diffing
 * pack versions.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
interface DeclaresExpressibleToolShapes
{
    /** @return non-empty-list<ToolShape> */
    public function expressibleToolShapes(): array;
}
