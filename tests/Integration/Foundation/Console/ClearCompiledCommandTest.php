<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClearCompiledCommandTest extends TestCase
{
    public function testDeletesCachedPackagesFile()
    {
        $path = $this->app->getCachedPackagesPath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, '<?php return [];');
        $this->assertFileExists($path);

        $this->artisan('clear-compiled')->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }

    public function testSucceedsWhenNoFilesExist()
    {
        @unlink($this->app->getCachedPackagesPath());

        $this->artisan('clear-compiled')->assertSuccessful();
    }
}
