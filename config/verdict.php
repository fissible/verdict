<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\NullEvidenceRecorder;

return [
    'evidence' => [
        'recorder' => NullEvidenceRecorder::class,
    ],

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],
];
