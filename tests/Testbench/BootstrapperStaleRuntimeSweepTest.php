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
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

class BootstrapperStaleRuntimeSweepTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    // Isolation keeps forked child exits from running shutdown callbacks or
    // result-writing state inherited from the live PHPUnit worker.
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itSerializesConcurrentStaleRuntimeSweeps(): void
    {
        $filesystem = new Filesystem;
        $scratchPath = ParallelTesting::tempDir('BootstrapperStaleRuntimeSweepTest');
        $startPath = $scratchPath . '/start';
        $statePath = $scratchPath . '/state';
        $failurePath = $scratchPath . '/failures';
        $staleRuntimePath = $scratchPath . '/hypervel-components-testbench-sweep-99999999';
        $bootstrapper = new ReflectionClass(Bootstrapper::class);
        $previousFilesystem = $bootstrapper->getStaticPropertyValue('filesystem');
        $purgeStaleRuntimeCopies = new ReflectionMethod(Bootstrapper::class, 'purgeStaleRuntimeCopies');
        $children = [];

        if (is_dir($scratchPath)) {
            $filesystem->deleteDirectory($scratchPath);
        }

        mkdir($staleRuntimePath . '/nested', 0777, true);
        file_put_contents($staleRuntimePath . '/nested/stale.txt', 'stale');
        file_put_contents($statePath, json_encode(['active' => 0, 'maximum' => 0], JSON_THROW_ON_ERROR));

        $bootstrapper->setStaticPropertyValue(
            'filesystem',
            new ConcurrentRuntimeDeletionFilesystem($staleRuntimePath, $statePath),
        );

        try {
            for ($index = 0; $index < 6; ++$index) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    while (! is_file($startPath)) {
                        usleep(1000);
                    }

                    try {
                        $purgeStaleRuntimeCopies->invoke(null, $scratchPath, getmypid());

                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents(
                            $failurePath,
                            $exception::class . ': ' . $exception->getMessage() . PHP_EOL,
                            FILE_APPEND | LOCK_EX,
                        );

                        exit(1);
                    }
                }

                $this->assertGreaterThan(0, $pid);
                $children[$pid] = $pid;
            }

            file_put_contents($startPath, 'start');

            foreach ($children as $pid) {
                $waitedPid = pcntl_waitpid($pid, $status);
                unset($children[$pid]);

                $this->assertSame($pid, $waitedPid);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(
                    0,
                    pcntl_wexitstatus($status),
                    is_file($failurePath) ? file_get_contents($failurePath) : '',
                );
            }

            $state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $state['maximum']);
            $this->assertDirectoryDoesNotExist($staleRuntimePath);
        } finally {
            foreach ($children as $pid) {
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }

            $bootstrapper->setStaticPropertyValue('filesystem', $previousFilesystem);

            if (is_dir($scratchPath)) {
                $filesystem->deleteDirectory($scratchPath);
            }
        }
    }
}

class ConcurrentRuntimeDeletionFilesystem extends Filesystem
{
    public function __construct(
        protected string $staleRuntimePath,
        protected string $statePath,
    ) {
    }

    /**
     * Delay stale deletion long enough to expose concurrent sweepers.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        if ($directory !== $this->staleRuntimePath) {
            return parent::deleteDirectory($directory, $preserve);
        }

        $this->updateState(static function (array $state): array {
            ++$state['active'];
            $state['maximum'] = max($state['maximum'], $state['active']);

            return $state;
        });

        usleep(50000);

        try {
            return parent::deleteDirectory($directory, $preserve);
        } finally {
            $this->updateState(static function (array $state): array {
                --$state['active'];

                return $state;
            });
        }
    }

    /**
     * Atomically update the shared deletion state.
     *
     * @param callable(array{active: int, maximum: int}): array{active: int, maximum: int} $callback
     * @return array{active: int, maximum: int}
     */
    protected function updateState(callable $callback): array
    {
        $handle = fopen($this->statePath, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open deletion state [{$this->statePath}].");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Unable to lock deletion state [{$this->statePath}].");
            }

            rewind($handle);
            $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
            $state = $callback($state);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $state;
        } finally {
            fclose($handle);
        }
    }
}
