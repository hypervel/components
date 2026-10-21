<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Tests\Mcp\TestCase;

class MakeServerCommandTest extends TestCase
{
    public function testCanCreateAServerClass(): void
    {
        $response = $this->artisan('make:mcp-server', [
            'name' => 'TestServer',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Servers/TestServer.php'));
    }

    public function testMayPublishACustomStub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-server.stub'));
    }
}
