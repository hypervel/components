<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class ConfigClearCommandTest extends TestCase
{
    use VerifiesDoubles;

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
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(true);
        $this->app->instance(Filesystem::class, $files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to delete the configuration cache file [{$path}].");

        $this->artisan('config:clear');
    }

    public function testConfigClearCommandSucceedsWhenCacheDisappearsConcurrently(): void
    {
        $path = $this->app->getCachedConfigPath();
        $files = Double::for(Filesystem::class)->strict();
        $files->expects('delete')->with($path)->returns(false);
        $files->expects('exists')->with($path)->returns(false);
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('config:clear')
            ->expectsOutputToContain('Configuration cache cleared successfully.')
            ->assertSuccessful();
    }
}
