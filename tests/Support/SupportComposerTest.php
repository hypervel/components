<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

use function Hypervel\Support\php_binary;

class SupportComposerTest extends TestCase
{
    protected string $tempDir;

    protected ?string $originalComposerVendorDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $originalComposerVendorDirectory = Env::get('COMPOSER_VENDOR_DIR');
        $this->originalComposerVendorDirectory = is_string($originalComposerVendorDirectory)
            ? $originalComposerVendorDirectory
            : null;

        Env::deleteMany(['COMPOSER_VENDOR_DIR']);
        Env::flushRepository();

        $this->tempDir = ParallelTesting::tempDir('SupportComposerTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        Env::deleteMany(['COMPOSER_VENDOR_DIR']);
        Env::flushRepository();

        if ($this->originalComposerVendorDirectory !== null) {
            Env::getRepository()->set('COMPOSER_VENDOR_DIR', $this->originalComposerVendorDirectory);
        }

        parent::tearDown();
    }

    public function testGetLoader(): void
    {
        $loader = Composer::getLoader();

        $this->assertInstanceOf(ClassLoader::class, $loader);
    }

    public function testSetAndGetLoader(): void
    {
        $original = Composer::getLoader();
        $custom = new ClassLoader;

        Composer::setLoader($custom);

        $this->assertSame($custom, Composer::getLoader());

        Composer::setLoader($original);
    }

    public function testFindsTheApplicationLoaderAfterAnAuxiliaryLoaderIsPrepended(): void
    {
        $original = Composer::getLoader();
        $auxiliary = new ClassLoader;
        $auxiliary->setClassMapAuthoritative(true);
        $auxiliary->register(true);

        try {
            Composer::flushState();

            $this->assertSame($original, Composer::getLoader());
        } finally {
            $auxiliary->unregister();
            Composer::setLoader($original);
        }
    }

    public function testFindsTheApplicationLoaderAfterAPharLoaderIsPrepended(): void
    {
        $original = Composer::getLoader();
        $pharLoader = new ClassLoader('phar:///opt/tool.phar/vendor');
        $pharLoader->setClassMapAuthoritative(true);
        $pharLoader->register(true);

        try {
            Composer::flushState();

            $this->assertSame($original, Composer::getLoader());
        } finally {
            $pharLoader->unregister();
            Composer::setLoader($original);
        }
    }

    public function testRootPackageSkipsAnInstallationInsideAPhar(): void
    {
        $vendorPath = $this->tempDir . '/tool/vendor';
        mkdir($vendorPath . '/composer', 0777, true);
        file_put_contents(
            $vendorPath . '/composer/installed.php',
            "<?php return ['root' => ['name' => 'tool/tool', 'install_path' => 'phar:///opt/tool.phar/'], 'versions' => []];",
        );

        $loader = new ClassLoader($vendorPath);
        $loader->setClassMapAuthoritative(true);
        $loader->register(true);

        try {
            $this->assertSame('tool/tool', InstalledVersions::getRootPackage()['name']);
            $this->assertSame('hypervel/components', Composer::rootPackage()['name']);
        } finally {
            $loader->unregister();
        }
    }

    public function testVendorPathUsesTheLoadedInstallOwningTheRoot(): void
    {
        $this->assertSame(dirname(__DIR__, 2) . '/vendor', Composer::vendorPath(dirname(__DIR__, 2)));
    }

    public function testVendorPathUsesACustomVendorDirectoryOfTheLoadedInstall(): void
    {
        $vendorPath = $this->tempDir . '/libs';
        mkdir($vendorPath . '/composer', 0777, true);
        file_put_contents(
            $vendorPath . '/composer/installed.php',
            "<?php return ['root' => ['install_path' => __DIR__ . '/../../']];",
        );

        $loader = new ClassLoader($vendorPath);
        $loader->register();

        try {
            $this->assertSame($vendorPath, Composer::vendorPath($this->tempDir));
        } finally {
            $loader->unregister();
        }
    }

    public function testVendorPathDefaultsToTheRootVendorDirectoryWithoutALoadedInstall(): void
    {
        $this->assertSame($this->tempDir . '/vendor', Composer::vendorPath($this->tempDir));
    }

    #[TestWith(['/custom/vendor', '/custom/vendor'])]
    #[TestWith(['libs', '{root}/libs'])]
    public function testVendorPathPrefersComposerVendorDirEnvironmentVariable(string $value, string $expected): void
    {
        Env::getRepository()->set('COMPOSER_VENDOR_DIR', $value);

        $this->assertSame(
            str_replace('{root}', dirname(__DIR__, 2), $expected),
            Composer::vendorPath(dirname(__DIR__, 2)),
        );
    }

    public function testDumpAutoloadRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload']);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWhenCustomComposerPharIsPresent(): void
    {
        $expectedProcessArguments = [php_binary(), 'composer.phar', 'dump-autoload'];

        $composer = $this->mockComposer($expectedProcessArguments, customComposerPhar: true);

        $composer->dumpAutoloads();
    }

    public function testDumpAutoloadRunsTheCorrectCommandWithExtraArguments(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--no-scripts']);

        $composer->dumpAutoloads('--no-scripts');
    }

    public function testDumpOptimizedTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(['composer', 'dump-autoload', '--optimize']);

        $composer->dumpOptimized();
    }

    public function testRequirePackagesRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(
            ['composer', 'require', 'pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0', '--dev'],
            expectedEnvironment: ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $composer->requirePackages(['pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0'], true);
    }

    public function testRemovePackagesRunsTheCorrectCommand(): void
    {
        $composer = $this->mockComposer(
            ['composer', 'remove', 'phpunit/phpunit', '--dev'],
            expectedEnvironment: ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $composer->removePackages(['phpunit/phpunit'], true);
    }

    #[TestWith(['requirePackages'])]
    #[TestWith(['removePackages'])]
    public function testOutputFailureStopsTheComposerProcess(string $method): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo "progress"; usleep(5000000);']);
        $composer = $this->getMockBuilder(Composer::class)
            ->onlyMethods(['getProcess'])
            ->setConstructorArgs([new Filesystem, sys_get_temp_dir()])
            ->getMock();
        $composer->expects($this->once())->method('getProcess')->willReturn($process);
        $failure = new RuntimeException('Unable to write process output.');
        $caught = null;

        try {
            try {
                $composer->{$method}(['example/package'], output: static fn () => throw $failure);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($failure, $caught);
            $this->assertFalse($process->isRunning());
        } finally {
            $process->stop(0);
        }
    }

    /**
     * Create a Composer manager expecting the given process arguments.
     */
    private function mockComposer(array $expectedProcessArguments, bool $customComposerPhar = false, ?array $expectedEnvironment = null): Composer
    {
        $directory = __DIR__;

        $files = m::mock(Filesystem::class);
        $files->expects('exists')->with($directory . '/composer.phar')->andReturn($customComposerPhar);

        $process = m::mock(Process::class);
        $process->expects('run');

        $composer = $this->getMockBuilder(Composer::class)
            ->onlyMethods(['getProcess'])
            ->setConstructorArgs([$files, $directory])
            ->getMock();
        $expectation = $composer->expects($this->once())->method('getProcess');

        if ($expectedEnvironment === null) {
            $expectation->with($expectedProcessArguments);
        } else {
            $expectation->with($expectedProcessArguments, $expectedEnvironment);
        }

        $expectation->willReturn($process);

        return $composer;
    }
}
