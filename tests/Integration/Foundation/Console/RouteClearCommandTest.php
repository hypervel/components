<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class RouteClearCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink($this->app->getCachedRoutesPath());

        parent::tearDown();
    }

    public function testRouteClearCommandDeletesCacheFile(): void
    {
        $path = $this->app->getCachedRoutesPath();

        file_put_contents($path, '<?php return [];');

        $this->artisan('route:clear')
            ->expectsOutputToContain('Route cache cleared successfully.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }

    public function testRouteClearCommandFailsWhenCacheFileRemains(): void
    {
        $path = $this->app->getCachedRoutesPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the route cache file [{$path}].");

        $this->artisan('route:clear');
    }

    public function testRouteClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedRoutesPath();
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();
        $files->shouldReceive('exists')->once()->with($path)->andReturnFalse();
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('route:clear')
            ->expectsOutputToContain('Route cache cleared successfully.')
            ->assertSuccessful();
    }
}
