<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use UnexpectedValueException;

class BootstrapperTest extends TestCase
{
    #[Test]
    public function testFlushStateKeepsRuntimePathForShutdownCleanup()
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $runtimePath = '/tmp/hypervel-components-testbench-flush-state';
        $previousConfiguration = $reflection->getStaticPropertyValue('configuration');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        try {
            $reflection->setStaticPropertyValue('configuration', new Config);
            $reflection->setStaticPropertyValue('runtimePath', $runtimePath);

            Bootstrapper::flushState();

            $this->assertNull($reflection->getStaticPropertyValue('configuration'));
            $this->assertSame($runtimePath, $reflection->getStaticPropertyValue('runtimePath'));
        } finally {
            $reflection->setStaticPropertyValue('configuration', $previousConfiguration);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    #[Test]
    public function itToleratesRuntimeDirectoryDeletionRacesWhenTheDirectoryIsGone(): void
    {
        $filesystem = new RuntimeDirectoryVanishedFilesystem;

        $this->deleteRuntimeDirectoryWithFilesystem($filesystem);

        $this->assertSame(1, $filesystem->deleteAttempts);
    }

    #[Test]
    public function itRethrowsRuntimeDirectoryDeletionFailuresWhenTheDirectoryRemains(): void
    {
        $filesystem = new RuntimeDirectoryStillPresentFilesystem;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('runtime directory still present');

        try {
            $this->deleteRuntimeDirectoryWithFilesystem($filesystem);
        } finally {
            $this->assertSame(2, $filesystem->deleteAttempts);
        }
    }

    #[Test]
    public function itCopiesThePackageEnvironmentFileIntoTheRuntimeCopy(): void
    {
        $packagePath = $this->temporaryDirectory('package-env');
        $sourcePath = $this->temporaryDirectory('skeleton-env');
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousServerPackageTester = $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousEnvironmentPackageTester = $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousProcessPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->setTestToken('bootstrapper-package-env');
            $this->setPackageTester();

            $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

            $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
            $this->assertSame('APP_NAME=Workbench', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
        } finally {
            $this->restoreTestToken($previousServerToken, $previousEnvironmentToken);
            $this->restorePackageTester($previousServerPackageTester, $previousEnvironmentPackageTester, $previousProcessPackageTester);
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath ?? null);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    #[Test]
    public function itCopiesTheSkeletonEnvironmentExampleIntoTheRuntimeCopyWhenNoPackageEnvironmentFileExists(): void
    {
        $packagePath = $this->temporaryDirectory('package-no-env');
        $sourcePath = $this->temporaryDirectory('skeleton-no-env');
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousServerPackageTester = $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousEnvironmentPackageTester = $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousProcessPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'REDIS_PASSWORD=null');

        try {
            $this->setTestToken('bootstrapper-skeleton-env');
            $this->setPackageTester();

            $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

            $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
            $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
            $this->assertSame('REDIS_PASSWORD=null', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
        } finally {
            $this->restoreTestToken($previousServerToken, $previousEnvironmentToken);
            $this->restorePackageTester($previousServerPackageTester, $previousEnvironmentPackageTester, $previousProcessPackageTester);
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath ?? null);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    #[Test]
    public function itDoesNotCopyPackageOrSkeletonEnvironmentFilesOutsidePackageTesterMode(): void
    {
        $packagePath = $this->temporaryDirectory('package-raw-env');
        $sourcePath = $this->temporaryDirectory('skeleton-raw-env');
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousServerPackageTester = $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousEnvironmentPackageTester = $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousProcessPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->setTestToken('bootstrapper-raw-env');
            $this->restorePackageTester(null, null, false);

            $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

            $this->assertFileDoesNotExist($runtimePath . DIRECTORY_SEPARATOR . '.env');
            $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
            $this->assertSame('APP_NAME=Skeleton', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env.example'));
        } finally {
            $this->restoreTestToken($previousServerToken, $previousEnvironmentToken);
            $this->restorePackageTester($previousServerPackageTester, $previousEnvironmentPackageTester, $previousProcessPackageTester);
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath ?? null);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    /**
     * Create a temporary test directory.
     */
    private function temporaryDirectory(string $name): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hypervel-bootstrapper-{$name}-"
            . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Create a runtime copy through Bootstrapper's protected method.
     */
    private function createRuntimeCopy(string $sourcePath, string $workingPath): string
    {
        $method = new ReflectionMethod(Bootstrapper::class, 'createRuntimeCopy');
        $method->setAccessible(true);

        return $method->invoke(null, $sourcePath, $workingPath);
    }

    /**
     * Delete a runtime directory through Bootstrapper with a fake filesystem.
     */
    private function deleteRuntimeDirectoryWithFilesystem(Filesystem $filesystem): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $method = new ReflectionMethod(Bootstrapper::class, 'deleteRuntimeDirectory');
        $previousFilesystem = $reflection->getStaticPropertyValue('filesystem');

        $method->setAccessible(true);

        try {
            $reflection->setStaticPropertyValue('filesystem', $filesystem);
            $method->invoke(null, '/tmp/hypervel-runtime-copy');
        } finally {
            $reflection->setStaticPropertyValue('filesystem', $previousFilesystem);
        }
    }

    /**
     * Set an isolated test token for runtime copy paths.
     */
    private function setTestToken(string $token): void
    {
        $_SERVER['TEST_TOKEN'] = $token;
        $_ENV['TEST_TOKEN'] = $token;
    }

    /**
     * Set package tester mode for runtime copy behavior.
     */
    private function setPackageTester(): void
    {
        $_SERVER['TESTBENCH_PACKAGE_TESTER'] = '(true)';
        $_ENV['TESTBENCH_PACKAGE_TESTER'] = '(true)';
        putenv('TESTBENCH_PACKAGE_TESTER=(true)');
        Env::flushRepository();
    }

    /**
     * Restore the previous test token.
     */
    private function restoreTestToken(?string $serverToken, ?string $environmentToken): void
    {
        if ($serverToken === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $serverToken;
        }

        if ($environmentToken === null) {
            unset($_ENV['TEST_TOKEN']);
        } else {
            $_ENV['TEST_TOKEN'] = $environmentToken;
        }
    }

    /**
     * Restore package tester mode.
     */
    private function restorePackageTester(
        ?string $serverPackageTester,
        ?string $environmentPackageTester,
        string|false $processPackageTester
    ): void {
        if ($processPackageTester === false) {
            putenv('TESTBENCH_PACKAGE_TESTER');
        } else {
            putenv("TESTBENCH_PACKAGE_TESTER={$processPackageTester}");
        }

        if ($serverPackageTester === null) {
            unset($_SERVER['TESTBENCH_PACKAGE_TESTER']);
        } else {
            $_SERVER['TESTBENCH_PACKAGE_TESTER'] = $serverPackageTester;
        }

        if ($environmentPackageTester === null) {
            unset($_ENV['TESTBENCH_PACKAGE_TESTER']);
        } else {
            $_ENV['TESTBENCH_PACKAGE_TESTER'] = $environmentPackageTester;
        }

        Env::flushRepository();
    }

    /**
     * Delete a temporary directory.
     */
    private function deleteDirectory(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        (new Filesystem)->deleteDirectory($path);
    }
}

class RuntimeDirectoryVanishedFilesystem extends Filesystem
{
    public int $deleteAttempts = 0;

    /**
     * Determine if the directory still exists.
     */
    public function isDirectory(string $directory): bool
    {
        return $this->deleteAttempts === 0;
    }

    /**
     * Simulate losing a concurrent deletion race.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        ++$this->deleteAttempts;

        throw new UnexpectedValueException('runtime directory vanished');
    }
}

class RuntimeDirectoryStillPresentFilesystem extends Filesystem
{
    public int $deleteAttempts = 0;

    /**
     * Determine if the directory still exists.
     */
    public function isDirectory(string $directory): bool
    {
        return true;
    }

    /**
     * Simulate a filesystem failure that leaves the directory behind.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        ++$this->deleteAttempts;

        throw new UnexpectedValueException('runtime directory still present');
    }
}
