<?php

declare(strict_types=1);

use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Tests\TestCase;
use Fissible\Verdict\Tests\WorkbenchTestCase;

uses(TestCase::class)->in('Feature');
uses(WorkbenchTestCase::class)->in('Workbench');

function acceptTestSnapshot(string $name = 'test-snapshot'): ExecutionTargetPolicy
{
    return ExecutionTargetPolicy::acceptStaleSnapshot(
        name: $name,
        identityUsing: static fn (mixed $envelope, mixed $target): array => [
            'target_type' => get_debug_type($target),
            'request_local_identity' => is_object($target)
                ? spl_object_id($target)
                : hash('sha256', serialize($target)),
        ],
    );
}
