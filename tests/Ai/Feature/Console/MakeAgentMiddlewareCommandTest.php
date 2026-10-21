<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Console;

use Hypervel\Support\Facades\File;
use Hypervel\Tests\Ai\TestCase;

class MakeAgentMiddlewareCommandTest extends TestCase
{
    /**
     * Remove the generated middleware from the shared test application.
     */
    protected function tearDown(): void
    {
        File::delete(app_path('Ai/Middleware/TestMiddleware.php'));

        parent::tearDown();
    }

    public function testCanCreateAnAgentMiddlewareClass(): void
    {
        $response = $this->artisan('make:agent-middleware', [
            'name' => 'TestMiddleware',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Middleware/TestMiddleware.php'));
        $this->assertStringContainsString('handle(PendingStep $step, Closure $next)', file_get_contents(app_path('Ai/Middleware/TestMiddleware.php')));
    }
}
