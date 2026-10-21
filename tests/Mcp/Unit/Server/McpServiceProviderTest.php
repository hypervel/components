<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server;

use Hypervel\Mcp\Server\McpServiceProvider;
use Hypervel\Passport\Passport;
use Hypervel\Tests\Mcp\TestCase;

class McpServiceProviderTest extends TestCase
{
    public function testRegistersMcpScopeDuringBoot(): void
    {
        Passport::$scopes = ['custom' => 'Custom scope'];

        $provider = new McpServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->boot();

        $this->assertArrayHasKey('mcp:use', Passport::$scopes);
        $this->assertSame('Custom scope', Passport::$scopes['custom']);
    }
}
