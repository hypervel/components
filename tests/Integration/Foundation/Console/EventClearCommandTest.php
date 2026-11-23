<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class EventClearCommandTest extends TestCase
{
    use VerifiesDoubles;

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
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(true);
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to delete the event cache file [{$path}].");

        $this->artisan('event:clear');
    }

    public function testEventsClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedEventsPath();
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(false);
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('event:clear')
            ->expectsOutputToContain('Cached events cleared successfully.')
            ->assertSuccessful();
    }
}
