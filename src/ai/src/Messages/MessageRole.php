<?php

declare(strict_types=1);

namespace Hypervel\Ai\Messages;

enum MessageRole: string
{
    case Assistant = 'assistant';
    case User = 'user';
    case ToolResult = 'tool_result';
}
