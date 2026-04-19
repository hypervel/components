<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Testbench\TestCase;

class EventCacheCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app->getCachedEventsPath());

        parent::tearDown();
    }

    public function testEventsCacheCommandCreatesFile()
    {
        $this->artisan('event:cache')
            ->assertSuccessful();

        $this->assertFileExists($this->app->getCachedEventsPath());
    }

    public function testEventsCacheCommandOutputsSuccessMessage()
    {
        $this->artisan('event:cache')
            ->expectsOutputToContain('Events cached successfully.')
            ->assertSuccessful();
    }

    public function testCachedFileContainsProviderEvents()
    {
        $this->artisan('event:cache')
            ->assertSuccessful();

        $cached = require $this->app->getCachedEventsPath();

        $this->assertIsArray($cached);
    }

    public function testEventsCacheCommandClearsOldCacheFirst()
    {
        $cachePath = $this->app->getCachedEventsPath();
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cachePath, '<?php return ["stale" => true];');

        $this->artisan('event:cache')
            ->assertSuccessful();

        $cached = require $cachePath;

        $this->assertArrayNotHasKey('stale', $cached);
    }
}
