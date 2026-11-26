<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server;

class MirroredHeaderServer extends Server
{
    public array $tools = [
        MirroredHeaderTool::class,
    ];
}
