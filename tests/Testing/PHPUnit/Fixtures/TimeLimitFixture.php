<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class TimeLimitFixture extends TestCase
{
    /**
     * Record that the test was torn down.
     */
    protected function tearDown(): void
    {
        $this->record('tearDown ' . $this->name());

        parent::tearDown();
    }

    public function testNonYieldingWorkIsAborted(): void
    {
        while (true);
    }

    public function testSleepingWorkIsAborted(): void
    {
        Coroutine::sleep(30);
        $this->fail('The sleeping test was not interrupted.');
    }

    public function testPendingChildrenAreAborted(): void
    {
        $this->startPendingChild();
    }

    public function testSkippedTestWithPendingChildrenIsAborted(): void
    {
        $this->startPendingChild();

        $this->markTestSkipped('The test skipped itself.');
    }

    public function testIncompleteTestWithPendingChildrenIsAborted(): void
    {
        $this->startPendingChild();

        $this->markTestIncomplete('The test marked itself incomplete.');
    }

    public function testAssertionFailureIsReportedWhenChildrenReachTheTimeLimit(): void
    {
        $this->startPendingChild();

        $this->fail('The original assertion failure.');
    }

    public function testErrorIsReportedWhenChildrenReachTheTimeLimit(): void
    {
        $this->startPendingChild();

        throw new RuntimeException('The original error.');
    }

    public function testExpectedExceptionFailsWhenChildrenReachTheTimeLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->startPendingChild();

        throw new RuntimeException('The expected exception.');
    }

    public function testExpectedAssertionFailureFailsWhenChildrenReachTheTimeLimit(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->startPendingChild();

        $this->fail('The expected assertion failure.');
    }

    public function testExpectedExceptionWaitsForItsChildren(): void
    {
        $this->expectException(RuntimeException::class);
        $this->startCompletingChild();

        throw new RuntimeException('The expected exception.');
    }

    public function testExpectedAssertionFailureWaitsForItsChildren(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->startCompletingChild();

        $this->fail('The expected assertion failure.');
    }

    public function testChildrenCompleteNormally(): void
    {
        $root = Coroutine::getCid();
        Coroutine::create(function () use ($root): void {
            $this->assertTrue(EngineCoroutine::join([$root]));
        });

        Coroutine::create(function (): void {
            $child = Coroutine::create(static function (): void {
                Coroutine::sleep(0.01);
            });

            $this->assertTrue(EngineCoroutine::join([$child]));
        });
    }

    public function testWorkWithoutTimeLimitCompletes(): void
    {
        $this->assertTrue(Coroutine::sleep(0.01));
    }

    /**
     * Start a child that waits until the time limit cancels it.
     */
    protected function startPendingChild(): void
    {
        Coroutine::create(function (): void {
            $this->assertFalse((new Channel)->pop());
        });
    }

    /**
     * Start a child that finishes after the test method has returned or thrown.
     */
    protected function startCompletingChild(): void
    {
        Coroutine::create(function (): void {
            $this->record(Coroutine::sleep(0.05) ? 'child completed' : 'child cancelled');
        });
    }

    /**
     * Record an event in the log the fixture's runner reads.
     */
    protected function record(string $event): void
    {
        file_put_contents((string) getenv('TIME_LIMIT_FIXTURE_LOG'), $event . PHP_EOL, FILE_APPEND);
    }
}
