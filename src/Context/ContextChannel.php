<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

enum ContextChannel: string
{
    case UserInput = 'user_input';
    case RetrievedDocument = 'retrieved_document';
    case ToolResult = 'tool_result';
    case ApplicationContext = 'application_context';
}
