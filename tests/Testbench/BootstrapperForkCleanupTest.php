<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

class BootstrapperForkCleanupTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itLeavesRuntimeCleanupToTheProcessThatRegisteredIt(): void
    {
        $scratchPath = sys_get_temp_dir() . '/hypervel-bootstrapper-fork-cleanup-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
        $packagePath = $scratchPath . '/package';
        $sourcePath = $scratchPath . '/source';
        $observationPath = $scratchPath . '/child-shutdown';
        $runtimePath = null;
        $filesystem = new Filesystem;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath . '/purge-directory', 0777, true);
        file_put_contents($sourcePath . '/purge-file', 'owned');

        try {
            $token = 'bootstrapper-fork-owner-' . getmypid() . '-' . bin2hex(random_bytes(6));
            $_SERVER['TEST_TOKEN'] = $token;
            $_ENV['TEST_TOKEN'] = $token;

            $createRuntimeCopy = new ReflectionMethod(Bootstrapper::class, 'createRuntimeCopy');
            $runtimePath = $createRuntimeCopy->invoke(null, $sourcePath, $packagePath);

            // Global state must stay disabled so this process cannot inherit
            // and clean the parent PHPUnit worker's BASE_PATH.
            $this->assertFalse(defined('BASE_PATH'));
            define('BASE_PATH', $runtimePath);

            $configuration = new Config([
                'purge' => [
                    'directories' => ['purge-directory'],
                    'files' => ['purge-file'],
                ],
            ]);
            $bootstrapper = new ReflectionClass(Bootstrapper::class);
            $bootstrapper->setStaticPropertyValue('configuration', $configuration);

            $this->assertSame([
                'directories' => ['purge-directory'],
                'files' => ['purge-file'],
            ], $configuration->getPurgeAttributes());

            (new ReflectionMethod(Bootstrapper::class, 'registerPurgeFiles'))->invoke(null);

            $pid = pcntl_fork();

            if ($pid === 0) {
                // Registered last so this observes both inherited Testbench callbacks.
                register_shutdown_function(static function () use ($runtimePath, $observationPath): void {
                    file_put_contents($observationPath, implode(':', [
                        (int) is_dir($runtimePath),
                        (int) is_dir($runtimePath . '/purge-directory'),
                        (int) is_file($runtimePath . '/purge-file'),
                    ]));
                });

                exit(0);
            }

            $this->assertGreaterThan(0, $pid);
            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertFileExists($observationPath);
            $this->assertSame('1:1:1', file_get_contents($observationPath));
            $this->assertDirectoryExists($runtimePath);
            $this->assertDirectoryExists($runtimePath . '/purge-directory');
            $this->assertFileExists($runtimePath . '/purge-file');
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
        $scratchPath = sys_get_temp_dir() . '/hypervel-bootstrapper-owner-cleanup-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
        $packagePath = $scratchPath . '/package';
        $sourcePath = $scratchPath . '/source';
        $purgeBasePath = $scratchPath . '/purge-base';
        $observationPath = $scratchPath . '/child-setup';
        $filesystem = new Filesystem;
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($purgeBasePath . '/purge-directory', 0777, true);
        file_put_contents($purgeBasePath . '/purge-file', 'owned');

        try {
            $token = 'bootstrapper-owner-cleanup-' . getmypid() . '-' . bin2hex(random_bytes(6));
            $_SERVER['TEST_TOKEN'] = $token;
            $_ENV['TEST_TOKEN'] = $token;

            $this->assertFalse(defined('BASE_PATH'));
            define('BASE_PATH', $purgeBasePath);

            $pid = pcntl_fork();

            if ($pid === 0) {
                $configuration = new Config([
                    'purge' => [
                        'directories' => ['purge-directory'],
                        'files' => ['purge-file'],
                    ],
                ]);
                $bootstrapper = new ReflectionClass(Bootstrapper::class);
                $bootstrapper->setStaticPropertyValue('configuration', $configuration);
                (new ReflectionMethod(Bootstrapper::class, 'registerPurgeFiles'))->invoke(null);

                $runtimePath = (new ReflectionMethod(Bootstrapper::class, 'createRuntimeCopy'))
                    ->invoke(null, $sourcePath, $packagePath);

                file_put_contents($observationPath, implode(':', [
                    (int) is_dir($runtimePath),
                    (int) is_dir($purgeBasePath . '/purge-directory'),
                    (int) is_file($purgeBasePath . '/purge-file'),
                ]));

                exit(0);
            }

            $this->assertGreaterThan(0, $pid);
            $tempDirectory = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            $runtimePath = $tempDirectory . "/hypervel-components-testbench-{$token}-{$pid}";

            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertFileExists($observationPath);
            $this->assertSame('1:1:1', file_get_contents($observationPath));
            $this->assertDirectoryDoesNotExist($runtimePath);
            $this->assertDirectoryDoesNotExist($purgeBasePath . '/purge-directory');
            $this->assertFileDoesNotExist($purgeBasePath . '/purge-file');
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
