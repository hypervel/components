<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

enum FinishReason: string
{
    case Stop = 'stop';
    case ToolCalls = 'tool_calls';
    case Continue = 'continue';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Error = 'error';
    case Unknown = 'unknown';
}
