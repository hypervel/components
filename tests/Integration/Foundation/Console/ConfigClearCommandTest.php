<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class ConfigClearCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app->getCachedConfigPath());

        parent::tearDown();
    }

    public function testConfigClearCommandDeletesCacheFile(): void
    {
        $path = $this->app->getCachedConfigPath();

        file_put_contents($path, '<?php return [];');

        $this->artisan('config:clear')
            ->expectsOutputToContain('Configuration cache cleared successfully.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }

    public function testConfigClearCommandFailsWhenCacheFileRemains(): void
    {
        $path = $this->app->getCachedConfigPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the configuration cache file [{$path}].");

        $this->artisan('config:clear');
    }

    public function testConfigClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedConfigPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('config:clear')
            ->expectsOutputToContain('Configuration cache cleared successfully.')
            ->assertSuccessful();
    }
}
