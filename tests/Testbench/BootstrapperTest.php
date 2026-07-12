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
    public function testFlushStateKeepsRuntimePathForShutdownCleanup(): void
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
        $runtimePath = null;

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-package-env', true, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertSame('APP_NAME=Workbench', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itCopiesTheSkeletonEnvironmentExampleIntoTheRuntimeCopyWhenNoPackageEnvironmentFileExists(): void
    {
        $packagePath = $this->temporaryDirectory('package-no-env');
        $sourcePath = $this->temporaryDirectory('skeleton-no-env');
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'REDIS_PASSWORD=null');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-skeleton-env', true, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
                $this->assertSame('REDIS_PASSWORD=null', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itDoesNotCopyPackageOrSkeletonEnvironmentFilesOutsidePackageTesterMode(): void
    {
        $packagePath = $this->temporaryDirectory('package-raw-env');
        $sourcePath = $this->temporaryDirectory('skeleton-raw-env');
        $runtimePath = null;

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-raw-env', false, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileDoesNotExist($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
                $this->assertSame('APP_NAME=Skeleton', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env.example'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itWritesAPrivateProcessIncarnationMarker(): void
    {
        $packagePath = $this->temporaryDirectory('identity-package');
        $sourcePath = $this->temporaryDirectory('identity-skeleton');
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $this->withRuntimeCopyEnvironment(
                'bootstrapper-identity',
                false,
                function () use (
                    $sourcePath,
                    $packagePath,
                    &$runtimePath,
                ): void {
                    $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);
                },
            );

            $marker = $runtimePath . DIRECTORY_SEPARATOR . '.testbench-process';
            $identity = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([
                'pid' => getmypid(),
                'started_at' => BootstrapperIdentityProbe::startIdentity(getmypid()),
            ], $identity);
            $this->assertSame(0600, fileperms($marker) & 0777);
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRecognizesAMatchingServeProcessIdentity(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/src/testbench/bin/testbench serve --host=127.0.0.1',
                'start-identity',
            );

            $this->assertTrue(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsACommandThatIsNotTestbenchServe(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/artisan queue:work',
                'start-identity',
            );

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAReusedPidWithADifferentStartIdentity(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/src/testbench/bin/testbench serve',
                'different-start-identity',
            );

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAMalformedProcessMarker(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            file_put_contents($runtimePath . '/.testbench-process', '{invalid');
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/src/testbench/bin/testbench serve',
                'start-identity',
            );

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAnUnreadableProcessIdentity(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setProcessIdentity(null, null);

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAMismatchedRuntimePidFile(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            file_put_contents(
                $runtimePath . '/storage/framework/hypervel.pid',
                (string) ($pid + 1),
            );
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/src/testbench/bin/testbench serve',
                'start-identity',
            );

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsADeadPidBeforeInspectingItsIdentity(): void
    {
        [$runtimePath] = $this->createServeIdentityRuntime();
        $deadPid = 2_000_000_000;

        try {
            file_put_contents(
                $runtimePath . '/storage/framework/hypervel.pid',
                (string) $deadPid,
            );
            BootstrapperIdentityProbe::setProcessIdentity(
                '/usr/bin/php /workspace/src/testbench/bin/testbench serve',
                'start-identity',
            );

            $this->assertFalse(
                BootstrapperIdentityProbe::isOrphanedServe($deadPid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetProcessIdentity();
            $this->deleteDirectory($runtimePath);
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
     * Run a callback with isolated runtime-copy environment state.
     */
    private function withRuntimeCopyEnvironment(string $token, bool $packageTester, callable $callback): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousServerPackageTester = $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousEnvironmentPackageTester = $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousProcessPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        try {
            $this->setTestToken($token);

            if ($packageTester) {
                $this->setPackageTester();
            } else {
                $this->restorePackageTester(null, null, false);
            }

            $callback();
        } finally {
            $this->restoreTestToken($previousServerToken, $previousEnvironmentToken);
            $this->restorePackageTester($previousServerPackageTester, $previousEnvironmentPackageTester, $previousProcessPackageTester);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    /**
     * Create a runtime directory containing matching serve identity markers.
     *
     * @return array{string, int}
     */
    private function createServeIdentityRuntime(): array
    {
        $runtimePath = $this->temporaryDirectory('serve-identity');
        $pid = getmypid();

        mkdir($runtimePath . '/storage/framework', 0777, true);
        file_put_contents(
            $runtimePath . '/storage/framework/hypervel.pid',
            (string) $pid,
        );
        file_put_contents(
            $runtimePath . '/.testbench-process',
            json_encode([
                'pid' => $pid,
                'started_at' => 'start-identity',
            ], JSON_THROW_ON_ERROR),
        );

        return [$runtimePath, $pid];
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

class BootstrapperIdentityProbe extends Bootstrapper
{
    protected static ?string $command = null;

    protected static ?string $startIdentity = null;

    public static function setProcessIdentity(?string $command, ?string $startIdentity): void
    {
        static::$command = $command;
        static::$startIdentity = $startIdentity;
    }

    public static function resetProcessIdentity(): void
    {
        static::$command = null;
        static::$startIdentity = null;
    }

    public static function matchesServeProcess(int $pid, string $runtimeDir): bool
    {
        return parent::matchesServeProcessIdentity($pid, $runtimeDir);
    }

    public static function isOrphanedServe(int $pid, string $runtimeDir): bool
    {
        return parent::isOrphanedServeProcess($pid, $runtimeDir);
    }

    protected static function processCommand(int $pid): ?string
    {
        return static::$command;
    }

    public static function startIdentity(int $pid): ?string
    {
        return parent::processStartIdentity($pid);
    }

    protected static function processStartIdentity(int $pid): ?string
    {
        return static::$startIdentity;
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
