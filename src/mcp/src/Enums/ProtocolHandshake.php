<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Enums;

enum ProtocolHandshake
{
    case Initialize;
    case Discovery;
}
