<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Tests\Mcp\TestCase;

class MakeToolCommandTest extends TestCase
{
    public function testCanCreateAToolClass(): void
    {
        $response = $this->artisan('make:mcp-tool', [
            'name' => 'TestTool',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Tools/TestTool.php'));
    }

    public function testMayPublishACustomStub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-tool.stub'));
    }
}
