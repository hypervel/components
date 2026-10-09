<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Console;

use Hypervel\Support\Facades\File;
use Hypervel\Tests\Ai\TestCase;

class MakeToolCommandTest extends TestCase
{
    /**
     * Remove the generated tool from the shared test application.
     */
    protected function tearDown(): void
    {
        File::delete(app_path('Ai/Tools/TestTool.php'));

        parent::tearDown();
    }

    public function testCanCreateAToolClass(): void
    {
        $response = $this->artisan('make:tool', [
            'name' => 'TestTool',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Ai/Tools/TestTool.php'));
    }
}
