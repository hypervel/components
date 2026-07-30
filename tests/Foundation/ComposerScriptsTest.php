<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\ComposerScripts;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ComposerScriptsTest extends TestCase
{
    protected string $tempDir;

    /** @var array<string, mixed> */
    protected array $previousCachePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('ComposerScriptsTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->tempDir);
        $files->ensureDirectoryExists($this->tempDir);

        foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE'] as $key) {
            $this->previousCachePaths[$key] = Env::get($key);
        }
    }

    protected function tearDown(): void
    {
        Env::deleteMany(['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE']);
        Env::flushRepository();

        foreach ($this->previousCachePaths as $key => $value) {
            if ($value !== null) {
                Env::getRepository()->set($key, $value);
            }
        }

        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testClearCompiledDeletesConfigurationAndPackageCaches(): void
    {
        $configPath = $this->tempDir . '/config.php';
        $packagesPath = $this->tempDir . '/packages.php';
        file_put_contents($configPath, '<?php return [];');
        file_put_contents($packagesPath, '<?php return [];');
        $this->setCachePaths($configPath, $packagesPath);

        TestableComposerScripts::clearCompiled();

        $this->assertFileDoesNotExist($configPath);
        $this->assertFileDoesNotExist($packagesPath);
    }

    public function testClearCompiledFailsWhenConfigurationCacheRemains(): void
    {
        $path = $this->undeletableFile();
        $this->setCachePaths($path, $this->tempDir . '/packages.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the configuration cache file [{$path}].");

        TestableComposerScripts::clearCompiled();
    }

    public function testClearCompiledFailsWhenPackageCacheRemains(): void
    {
        $path = $this->undeletableFile();
        $this->setCachePaths($this->tempDir . '/config.php', $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the compiled packages file [{$path}].");

        TestableComposerScripts::clearCompiled();
    }

    protected function setCachePaths(string $configPath, string $packagesPath): void
    {
        Env::deleteMany(['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE']);
        Env::flushRepository();
        Env::getRepository()->set('APP_CONFIG_CACHE', $configPath);
        Env::getRepository()->set('APP_PACKAGES_CACHE', $packagesPath);
    }

    protected function undeletableFile(): string
    {
        $path = '/proc/self/exe';

        if (! is_file($path)) {
            $this->markTestSkipped('The procfs executable link is unavailable on this platform.');
        }

        return $path;
    }
}

class TestableComposerScripts extends ComposerScripts
{
    public static function clearCompiled(): void
    {
        parent::clearCompiled();
    }
}
