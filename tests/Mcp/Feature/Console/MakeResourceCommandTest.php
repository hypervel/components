<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Tests\Mcp\TestCase;

class MakeResourceCommandTest extends TestCase
{
    public function testCanCreateAResourceClass(): void
    {
        $response = $this->artisan('make:mcp-resource', [
            'name' => 'TestResource',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Resources/TestResource.php'));
    }

    public function testMayPublishACustomStub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-resource.stub'));
    }
}
