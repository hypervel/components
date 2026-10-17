<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Tests\Mcp\TestCase;

class MakePromptCommandTest extends TestCase
{
    public function testCanCreateAPromptClass(): void
    {
        $response = $this->artisan('make:mcp-prompt', [
            'name' => 'TestPrompt',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Prompts/TestPrompt.php'));
    }

    public function testMayPublishACustomStub(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-prompt.stub'));
    }
}
