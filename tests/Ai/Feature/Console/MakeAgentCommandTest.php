<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Console;

use Hypervel\Support\Facades\File;
use Hypervel\Tests\Ai\TestCase;

class MakeAgentCommandTest extends TestCase
{
    /**
     * Remove generated files from the shared test application.
     */
    protected function tearDown(): void
    {
        File::delete([
            app_path('Ai/Agents/TestAgent.php'),
            app_path('Ai/Agents/StructuredAgent.php'),
            base_path('stubs/agent.stub'),
            base_path('stubs/structured-agent.stub'),
            base_path('stubs/tool.stub'),
            base_path('stubs/agent-middleware.stub'),
        ]);

        parent::tearDown();
    }

    public function testCanCreateAnAgentClass(): void
    {
        $response = $this->artisan('make:agent', [
            'name' => 'TestAgent',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Agents/TestAgent.php'));
    }

    public function testCanCreateAStructuredAgentClass(): void
    {
        $response = $this->artisan('make:agent', [
            'name' => 'StructuredAgent',
            '--structured' => true,
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Agents/StructuredAgent.php'));
    }

    public function testMayPublishCustomStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'ai-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/agent.stub'));
        $this->assertFileExists(base_path('stubs/structured-agent.stub'));
        $this->assertFileExists(base_path('stubs/tool.stub'));
        $this->assertFileExists(base_path('stubs/agent-middleware.stub'));
    }
}
