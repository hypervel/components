<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
#[RequiresPhpExtension('pdo_sqlite')]
class WorkerTimeoutTest extends TestCase
{
    protected string $directory;

    /**
     * Create isolated durable storage shared with the worker subprocesses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('WorkerTimeoutTest');
        (new Filesystem)->deleteDirectory($this->directory);
        mkdir($this->directory, 0777, true);
        touch($this->directory . '/queue.sqlite');
    }

    /**
     * Remove the worker's durable storage.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[DataProvider('timeoutModes')]
    public function testTimeoutRunsFailureHandlingBeforeTerminating(string $mode, string $cleanupOutput): void
    {
        $process = $this->runWorker($mode);

        $this->assertSame(SIGKILL, $process->getTermSignal());
        $this->assertSame("attempt:1\n" . $cleanupOutput . "failed-start\nfailed-finished\nfailure-recorded\ntimed-out\nstopping\nkill-callback\n", $process->getOutput());

        $database = new PDO('sqlite:' . $this->directory . '/queue.sqlite');
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $failures = $database->query('SELECT * FROM failed_jobs')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $failures);
        $this->assertSame('timeout-worker-job', $failures[0]['uuid']);
        $this->assertStringContainsString(TimeoutExceededException::class, $failures[0]['exception']);
    }

    /**
     * Cover blocked PHP and slow failure cleanup with a healthy event loop.
     */
    public static function timeoutModes(): array
    {
        return [
            'CPU-bound job' => ['fail', ''],
            'yielding failure cleanup outlives the alarm grace' => ['yielding', ''],
            'manually suspended job' => ['suspended', ''],
            'job cleanup finishes before failure handling' => ['unwind', "unwind-start\nunwind-finished\n"],
        ];
    }

    public function testTimedOutJobCanBeRetriedAfterItsReservationExpires(): void
    {
        $process = $this->runWorker('retry');
        $this->assertSame(SIGKILL, $process->getTermSignal());
        $this->assertSame("attempt:1\ntimed-out\nstopping\nkill-callback\n", $process->getOutput());

        $database = new PDO('sqlite:' . $this->directory . '/queue.sqlite');
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn());
        $job = $database->query('SELECT attempts, reserved_at FROM jobs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $job['attempts']);
        $this->assertNotNull($job['reserved_at']);

        // Advance only the reservation expiry; the next process uses the real queue pop path.
        $database->exec('UPDATE jobs SET reserved_at = 0');
        $retry = $this->runWorker('finish');
        $this->assertTrue($retry->isSuccessful());
        $this->assertSame("attempt:2\nretried\nstopping\nfinished\n", $retry->getOutput());
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    #[TestWith(['reblock'])]
    #[TestWith(['blocked-cleanup'])]
    public function testKernelAlarmTerminatesRecoveryThatBlocksAgain(string $mode): void
    {
        $process = $this->runWorker($mode);

        $this->assertSame(SIGALRM, $process->getTermSignal());
        $this->assertSame("attempt:1\nfailed-start\nfailed-finished\nfailure-recorded\ntimed-out\n", $process->getOutput());
    }

    #[TestWith(['unwind-stall', "unwind-start\n"])]
    #[TestWith(['late-park', ''])]
    public function testUnfinishedAttemptIsLeftReservedInsteadOfFailedConcurrently(string $mode, string $cleanupOutput): void
    {
        $process = $this->runWorker($mode);

        $this->assertSame(SIGKILL, $process->getTermSignal());
        $this->assertSame("attempt:1\n" . $cleanupOutput . "timed-out\nstopping\nkill-callback\n", $process->getOutput());
        $database = new PDO('sqlite:' . $this->directory . '/queue.sqlite');
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn());
        $jobs = $database->query('SELECT attempts, reserved_at FROM jobs')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $jobs);
        $this->assertSame(1, (int) $jobs[0]['attempts']);
        $this->assertNotNull($jobs[0]['reserved_at']);
    }

    #[TestWith(['released-timeout'])]
    #[TestWith(['catch-release'])]
    public function testReleasedAttemptIsNotFailedByTimeoutHandling(string $mode): void
    {
        $process = $this->runWorker($mode);

        $this->assertSame(SIGKILL, $process->getTermSignal());
        $this->assertSame("attempt:1\nreleased\ntimed-out\nstopping\nkill-callback\n", $process->getOutput());
        $database = new PDO('sqlite:' . $this->directory . '/queue.sqlite');
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn());
        $jobs = $database->query('SELECT attempts, reserved_at FROM jobs')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $jobs);
        $this->assertSame(1, (int) $jobs[0]['attempts']);
        $this->assertNull($jobs[0]['reserved_at']);
    }

    /**
     * Run real worker signals in a child with an independent outer time limit.
     */
    protected function runWorker(string $mode): Process
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/timeout-worker.php',
            $this->directory . '/queue.sqlite',
            $mode,
        ], timeout: 20);

        try {
            $process->run();
        } catch (ProcessSignaledException) {
            // Expected worker termination is asserted by the caller.
        } finally {
            $process->stop(0, SIGKILL);
        }

        $this->assertSame('', $process->getErrorOutput());

        return $process;
    }
}
