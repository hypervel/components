<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class TimeLimitTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

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
        ];
    }

    #[DataProvider('completedTests')]
    public function testCompletedWorkDoesNotTimeOut(string $method, int $limit): void
    {
        $process = $this->runFixture($method, $limit);

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Provide normal completion and disabled deadline cases.
     */
    public static function completedTests(): array
    {
        return [
            'child completes after root exits' => ['testChildrenCompleteNormally', 1],
            'no time limit' => ['testWorkWithoutTimeLimitCompletes', 0],
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
            timeout: 15,
        );
        $process->run();

        return $process;
    }
}
