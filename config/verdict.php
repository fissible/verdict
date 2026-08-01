<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\NullEvidenceRecorder;

return [
    'evidence' => [
        // InMemoryEvidenceRecorder is only for tests and local development. Its unbounded,
        // process-local state is unsafe for production, Octane, and queue workers.
        'recorder' => NullEvidenceRecorder::class,
    ],

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],
];
