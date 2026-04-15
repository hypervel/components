<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Testbench\TestCase;

class EventClearCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app->getCachedEventsPath());

        parent::tearDown();
    }

    public function testEventsClearCommandDeletesCacheFile()
    {
        $cachePath = $this->app->getCachedEventsPath();
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cachePath, '<?php return [];');
        $this->assertFileExists($cachePath);

        $this->artisan('event:clear')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($cachePath);
    }

    public function testEventsClearCommandOutputsSuccessMessage()
    {
        $this->artisan('event:clear')
            ->expectsOutputToContain('Cached events cleared successfully.')
            ->assertSuccessful();
    }

    public function testEventsClearCommandSucceedsWhenNoCacheExists()
    {
        $cachePath = $this->app->getCachedEventsPath();

        // Ensure no cache file exists
        @unlink($cachePath);

        $this->artisan('event:clear')
            ->assertSuccessful();
    }
}
