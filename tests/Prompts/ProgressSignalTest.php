<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Progress;
use Hypervel\Prompts\Prompt;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;

class ProgressSignalTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('ProgressSignalTest');

        (new Filesystem)->deleteDirectory($this->temporaryDirectory);
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testSigintSettlesAndExitsWhenCancellationRenderingFails(): void
    {
        $observationPath = $this->temporaryDirectory . '/shutdown-observation';
        $pid = pcntl_fork();

        if ($pid === 0) {
            Prompt::fake();
            $previousHandler = static function (): void {
            };
            $failure = new RuntimeException('unable to render progress cancellation');
            $output = new class($failure) extends BufferedConsoleOutput {
                public bool $failFrames = false;

                public bool $frameWriteAttempted = false;

                public function __construct(private RuntimeException $failure)
                {
                    parent::__construct(decorated: true);
                }

                public function writeDirectly(string $message): void
                {
                }

                protected function doWrite(string $message, bool $newline): void
                {
                    if ($this->failFrames) {
                        $this->frameWriteAttempted = true;

                        throw $this->failure;
                    }

                    parent::doWrite($message, $newline);
                }
            };

            pcntl_signal(SIGINT, $previousHandler);
            pcntl_async_signals(false);
            Prompt::setOutput($output);

            $progress = new Progress('Working', 1);
            $progress->start();
            $output->failFrames = true;
            $continuedAfterSignal = false;

            register_shutdown_function(static function () use (
                &$continuedAfterSignal,
                $observationPath,
                $output,
                $previousHandler,
            ): void {
                file_put_contents($observationPath, implode(':', [
                    (int) (pcntl_signal_get_handler(SIGINT) === $previousHandler),
                    (int) (pcntl_async_signals() === false),
                    (int) (! (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue()),
                    (int) $output->frameWriteAttempted,
                    (int) $continuedAfterSignal,
                ]));
            });

            posix_kill(getmypid(), SIGINT);
            $continuedAfterSignal = true;

            exit(1);
        }

        $this->assertGreaterThan(0, $pid);
        $this->assertSame($pid, pcntl_waitpid($pid, $status));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertFileExists($observationPath);
        $this->assertSame('1:1:1:1:0', file_get_contents($observationPath));
    }
}
