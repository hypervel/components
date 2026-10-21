<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Tests\TestCase;

class ServerContextTest extends TestCase
{
    public function testClampsPerPageToDefaultAndMaxValues(): void
    {
        $context = new ServerContext(
            supportedProtocolVersions: ['2025-03-26'],
            serverCapabilities: [],
            implementation: new Implementation('Test', '1.0.0'),
            instructions: 'x',
            maxPaginationLength: 50,
            defaultPaginationLength: 10,
            tools: [],
            resources: [],
            prompts: [],
        );

        $this->assertSame(10, $context->perPage());
        $this->assertSame(5, $context->perPage(5));
        $this->assertSame(10, $context->perPage(10));
        $this->assertSame(50, $context->perPage(100));
    }
}
