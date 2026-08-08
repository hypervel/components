<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class BootstrapperForkCleanupTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itLeavesRuntimeCleanupToTheProcessThatRegisteredIt(): void
    {
        $filesystem = new Filesystem;
        $scratchPath = ParallelTesting::tempDir('BootstrapperForkCleanupTest-fork-owner');
        $packagePath = $scratchPath . '/package';
        $sourcePath = $scratchPath . '/source';
        $observationPath = $scratchPath . '/child-shutdown';
        $runtimePath = null;

        if (is_dir($scratchPath)) {
            $filesystem->deleteDirectory($scratchPath);
        }

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $token = 'bootstrapper-fork-owner-' . getmypid() . '-' . bin2hex(random_bytes(6));
            $_SERVER['TEST_TOKEN'] = $token;
            $_ENV['TEST_TOKEN'] = $token;

            $createRuntimeCopy = new ReflectionMethod(Bootstrapper::class, 'createRuntimeCopy');
            $runtimePath = $createRuntimeCopy->invoke(null, $sourcePath, $packagePath);

            $pid = pcntl_fork();

            if ($pid === 0) {
                // Registered last so this observes both inherited Testbench callbacks.
                register_shutdown_function(static function () use ($runtimePath, $observationPath): void {
                    file_put_contents($observationPath, (string) (int) is_dir($runtimePath));
                });

                exit(0);
            }

            $this->assertGreaterThan(0, $pid);
            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertFileExists($observationPath);
            $this->assertSame('1', file_get_contents($observationPath));
            $this->assertDirectoryExists($runtimePath);
        } finally {
            if (is_string($runtimePath) && is_dir($runtimePath)) {
                $filesystem->deleteDirectory($runtimePath);
            }

            if (is_dir($scratchPath)) {
                $filesystem->deleteDirectory($scratchPath);
            }
        }
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itRunsRuntimeCleanupInTheProcessThatRegisteredIt(): void
    {
        $filesystem = new Filesystem;
        $scratchPath = ParallelTesting::tempDir('BootstrapperForkCleanupTest-owner-cleanup');
        $packagePath = $scratchPath . '/package';
        $sourcePath = $scratchPath . '/source';
        $observationPath = $scratchPath . '/child-setup';
        $runtimePath = null;

        if (is_dir($scratchPath)) {
            $filesystem->deleteDirectory($scratchPath);
        }

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $token = 'bootstrapper-owner-cleanup-' . getmypid() . '-' . bin2hex(random_bytes(6));
            $_SERVER['TEST_TOKEN'] = $token;
            $_ENV['TEST_TOKEN'] = $token;

            $pid = pcntl_fork();

            if ($pid === 0) {
                $runtimePath = (new ReflectionMethod(Bootstrapper::class, 'createRuntimeCopy'))
                    ->invoke(null, $sourcePath, $packagePath);

                file_put_contents($observationPath, (string) (int) is_dir($runtimePath));

                exit(0);
            }

            $this->assertGreaterThan(0, $pid);
            $tempDirectory = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            $runtimePath = $tempDirectory . "/hypervel-components-testbench-{$token}-{$pid}";

            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertFileExists($observationPath);
            $this->assertSame('1', file_get_contents($observationPath));
            $this->assertDirectoryDoesNotExist($runtimePath);
        } finally {
            if (is_string($runtimePath) && is_dir($runtimePath)) {
                $filesystem->deleteDirectory($runtimePath);
            }

            if (is_dir($scratchPath)) {
                $filesystem->deleteDirectory($scratchPath);
            }
        }
    }
}
