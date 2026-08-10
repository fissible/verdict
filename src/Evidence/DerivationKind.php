<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

enum DerivationKind: string
{
    case Retrieved = 'retrieved';
    case Summarized = 'summarized';
    case Transformed = 'transformed';
    case ToolResult = 'tool_result';
}
