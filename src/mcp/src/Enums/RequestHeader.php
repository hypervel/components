<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Enums;

enum RequestHeader: string
{
    case PROTOCOL_VERSION = 'MCP-Protocol-Version';
    case METHOD = 'Mcp-Method';
    case NAME = 'Mcp-Name';
}
