<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class EventClearCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app->getCachedEventsPath());

        parent::tearDown();
    }

    public function testEventsClearCommandDeletesCacheFile(): void
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

    public function testEventsClearCommandOutputsSuccessMessage(): void
    {
        $this->artisan('event:clear')
            ->expectsOutputToContain('Cached events cleared successfully.')
            ->assertSuccessful();
    }

    public function testEventsClearCommandSucceedsWhenNoCacheExists(): void
    {
        $cachePath = $this->app->getCachedEventsPath();

        @unlink($cachePath);

        $this->artisan('event:clear')
            ->assertSuccessful();
    }

    public function testEventsClearCommandFailsWhenCacheFileRemains(): void
    {
        $path = $this->app->getCachedEventsPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the event cache file [{$path}].");

        $this->artisan('event:clear');
    }

    public function testEventsClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedEventsPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('event:clear')
            ->expectsOutputToContain('Cached events cleared successfully.')
            ->assertSuccessful();
    }
}
