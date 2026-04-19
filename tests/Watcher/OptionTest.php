<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\Watcher\Driver\FswatchDriver;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;

class OptionTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDir = ParallelTesting::tempDir('OptionTest');
        mkdir($this->tempDir, 0777, true);

        // Create subdirectories for is_dir() checks in fromConfig()
        mkdir($this->tempDir . '/app', 0777, true);
        mkdir($this->tempDir . '/config', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testFromConfigParsesBareDirectory()
    {
        $option = Option::fromConfig(['watch' => ['app']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('app', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertNull($paths[0]->pattern);
    }

    public function testFromConfigParsesGlobWithExtension()
    {
        $option = Option::fromConfig(['watch' => ['config/**/*.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('config', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('config/**/*.php', $paths[0]->pattern);
    }

    public function testFromConfigParsesCompoundExtensionGlob()
    {
        $option = Option::fromConfig(['watch' => ['resources/**/*.blade.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('resources', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('resources/**/*.blade.php', $paths[0]->pattern);
    }

    public function testFromConfigParsesMiddleWildcard()
    {
        $option = Option::fromConfig(['watch' => ['app/*/Actions/*.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('app', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('app/*/Actions/*.php', $paths[0]->pattern);
    }

    public function testFromConfigParsesQuestionMarkGlob()
    {
        $option = Option::fromConfig(['watch' => ['routes/?.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('routes', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('routes/?.php', $paths[0]->pattern);
    }

    public function testFromConfigParsesBraceGlob()
    {
        $option = Option::fromConfig(['watch' => ['config/{app,queue}.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('config', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('config/{app,queue}.php', $paths[0]->pattern);
    }

    public function testFromConfigParsesBracketGlob()
    {
        $option = Option::fromConfig(['watch' => ['lang/[a-z][a-z].php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('lang', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('lang/[a-z][a-z].php', $paths[0]->pattern);
    }

    public function testFromConfigParsesSpecificFile()
    {
        $option = Option::fromConfig(['watch' => ['.env']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('.env', $paths[0]->path);
        $this->assertSame(WatchPathType::File, $paths[0]->type);
        $this->assertNull($paths[0]->pattern);
    }

    public function testFromConfigParsesDotlessFile()
    {
        $option = Option::fromConfig(['watch' => ['composer.json']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('composer.json', $paths[0]->path);
        $this->assertSame(WatchPathType::File, $paths[0]->type);
        $this->assertNull($paths[0]->pattern);
    }

    public function testFromConfigMergesExtraPaths()
    {
        $option = Option::fromConfig(
            ['watch' => ['app', '.env']],
            $this->tempDir,
            extraPaths: ['routes', 'composer.json'],
        );

        $paths = $option->getWatchPaths();
        $this->assertCount(4, $paths);
        $this->assertSame('app', $paths[0]->path);
        $this->assertSame('.env', $paths[1]->path);
        $this->assertSame('routes', $paths[2]->path);
        $this->assertSame(WatchPathType::Directory, $paths[2]->type);
        $this->assertSame('composer.json', $paths[3]->path);
    }

    public function testFromConfigDeduplicatesPaths()
    {
        $option = Option::fromConfig(
            ['watch' => ['app', '.env']],
            $this->tempDir,
            extraPaths: ['app', '.env'],
        );

        $paths = $option->getWatchPaths();
        $this->assertCount(2, $paths);
        $this->assertSame('app', $paths[0]->path);
        $this->assertSame('.env', $paths[1]->path);
    }

    public function testFromConfigUsesDefaultDriver()
    {
        $option = Option::fromConfig([], $this->tempDir);

        $this->assertSame(ScanFileDriver::class, $option->getDriver());
    }

    public function testFromConfigUsesConfiguredDriver()
    {
        $option = Option::fromConfig(['driver' => FswatchDriver::class], $this->tempDir);

        $this->assertSame(FswatchDriver::class, $option->getDriver());
    }

    public function testFromConfigUsesDefaultScanInterval()
    {
        $option = Option::fromConfig([], $this->tempDir);

        $this->assertSame(2000, $option->getScanInterval());
    }

    public function testFromConfigUsesConfiguredScanInterval()
    {
        $option = Option::fromConfig(['scan_interval' => 1500], $this->tempDir);

        $this->assertSame(1500, $option->getScanInterval());
    }

    public function testScanIntervalSecondsConversion()
    {
        $option = new Option(scanInterval: 2000);
        $this->assertSame(2.0, $option->getScanIntervalSeconds());

        $option = new Option(scanInterval: 1500);
        $this->assertSame(1.5, $option->getScanIntervalSeconds());
    }

    public function testGetDirectoryPathsFiltersCorrectly()
    {
        $option = new Option(watchPaths: [
            new WatchPath('app', WatchPathType::Directory),
            new WatchPath('.env', WatchPathType::File),
            new WatchPath('config', WatchPathType::Directory, 'config/**/*.php'),
        ]);

        $dirs = $option->getDirectoryPaths();
        $this->assertCount(2, $dirs);
        $this->assertSame('app', $dirs[0]->path);
        $this->assertSame('config', $dirs[1]->path);
    }

    public function testGetFilePathsFiltersCorrectly()
    {
        $option = new Option(watchPaths: [
            new WatchPath('app', WatchPathType::Directory),
            new WatchPath('.env', WatchPathType::File),
            new WatchPath('composer.json', WatchPathType::File),
        ]);

        $files = $option->getFilePaths();
        $this->assertCount(2, $files);
        $this->assertSame('.env', $files[0]->path);
        $this->assertSame('composer.json', $files[1]->path);
    }

    public function testGlobWithNoBaseDir()
    {
        $option = Option::fromConfig(['watch' => ['**/*.php']], $this->tempDir);

        $paths = $option->getWatchPaths();
        $this->assertCount(1, $paths);
        $this->assertSame('.', $paths[0]->path);
        $this->assertSame(WatchPathType::Directory, $paths[0]->type);
        $this->assertSame('**/*.php', $paths[0]->pattern);
    }

    public function testConstructorDirectlyWithWatchPaths()
    {
        $watchPaths = [
            new WatchPath('app', WatchPathType::Directory),
            new WatchPath('.env', WatchPathType::File),
        ];

        $option = new Option(
            driver: FswatchDriver::class,
            watchPaths: $watchPaths,
            scanInterval: 500,
        );

        $this->assertSame(FswatchDriver::class, $option->getDriver());
        $this->assertSame($watchPaths, $option->getWatchPaths());
        $this->assertSame(500, $option->getScanInterval());
        $this->assertSame(0.5, $option->getScanIntervalSeconds());
    }
}
