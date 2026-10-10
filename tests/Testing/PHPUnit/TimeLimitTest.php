<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class TimeLimitTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $tempDirectory;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('TimeLimitTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    #[DataProvider('timedOutTests')]
    public function testTimedOutTestsFailWithTheirIdentity(string $method): void
    {
        $process = $this->runFixture($method, 1);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('TimeLimitFixture::' . $method, $output);
        $this->assertStringContainsString('This test was aborted after 1 second', $output);
        $this->assertStringNotContainsString('deadlock', $output);
    }

    /**
     * Provide tests that must be interrupted by PHPUnit's deadline.
     */
    public static function timedOutTests(): array
    {
        return [
            'non-yielding' => ['testNonYieldingWorkIsAborted'],
            'sleeping root' => ['testSleepingWorkIsAborted'],
            'pending child after root exits' => ['testPendingChildrenAreAborted'],
            'pending child after a skip' => ['testSkippedTestWithPendingChildrenIsAborted'],
            'pending child after marking incomplete' => ['testIncompleteTestWithPendingChildrenIsAborted'],
        ];
    }

    #[DataProvider('failedTests')]
    public function testFailuresKeepTheirCauseWhenChildrenReachTheTimeLimit(string $method, array $messages): void
    {
        $process = $this->runFixture($method, 1);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('TimeLimitFixture::' . $method, $output);

        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $output);
        }

        $this->assertStringNotContainsString('This test was aborted', $output);
        $this->assertContains('tearDown ' . $method, $this->fixtureEvents());
    }

    /**
     * Provide tests that throw before their pending children reach the deadline.
     */
    public static function failedTests(): array
    {
        $childrenStillRunning = 'Child coroutines were still running at the time limit after the test threw an expected exception.';

        return [
            'assertion failure' => ['testAssertionFailureIsReportedWhenChildrenReachTheTimeLimit', ['The original assertion failure.']],
            'error' => ['testErrorIsReportedWhenChildrenReachTheTimeLimit', ['RuntimeException: The original error.']],
            'expected exception' => [
                'testExpectedExceptionFailsWhenChildrenReachTheTimeLimit',
                [$childrenStillRunning, 'RuntimeException: The expected exception.'],
            ],
            'expected assertion failure' => [
                'testExpectedAssertionFailureFailsWhenChildrenReachTheTimeLimit',
                [$childrenStillRunning, 'The expected assertion failure.'],
            ],
        ];
    }

    #[DataProvider('completedTests')]
    public function testCompletedWorkDoesNotTimeOut(string $method, int $limit, array $events): void
    {
        $process = $this->runFixture($method, $limit);

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());

        foreach ($events as $event) {
            $this->assertContains($event, $this->fixtureEvents());
        }
    }

    /**
     * Provide normal completion and disabled deadline cases.
     */
    public static function completedTests(): array
    {
        return [
            'child completes after root exits' => ['testChildrenCompleteNormally', 1, []],
            'no time limit' => ['testWorkWithoutTimeLimitCompletes', 0, []],
            'child completes after an expected exception' => ['testExpectedExceptionWaitsForItsChildren', 1, ['child completed']],
            'child completes after an expected assertion failure' => [
                'testExpectedAssertionFailureWaitsForItsChildren',
                1,
                ['child completed'],
            ],
        ];
    }

    /**
     * Run a fixture with its own PHPUnit time limit.
     */
    protected function runFixture(string $method, int $limit): Process
    {
        // Leave headroom for the native timeout report, but do not wait for the fixture's 30-second sleep.
        $process = new Process(
            command: [
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--no-progress',
                '--default-time-limit=' . $limit,
                '--filter=' . $method,
                'tests/Testing/PHPUnit/Fixtures/TimeLimitFixture.php',
            ],
            cwd: dirname(__DIR__, 3),
            env: ['TIME_LIMIT_FIXTURE_LOG' => $this->tempDirectory . '/events.log'],
            timeout: 15,
        );
        $process->run();

        return $process;
    }

    /**
     * Get the events the fixture recorded.
     *
     * @return list<string>
     */
    protected function fixtureEvents(): array
    {
        return file($this->tempDirectory . '/events.log', FILE_IGNORE_NEW_LINES);
    }
}
