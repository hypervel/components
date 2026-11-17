<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class RouteClearCommandTest extends TestCase
{
    use VerifiesDoubles;

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
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(true);
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to delete the route cache file [{$path}].");

        $this->artisan('route:clear');
    }

    public function testRouteClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedRoutesPath();
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(false);
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('route:clear')
            ->expectsOutputToContain('Route cache cleared successfully.')
            ->assertSuccessful();
    }
}
