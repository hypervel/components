<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\QueueFakeTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class QueueFakeTest extends TestCase
{
    /**
     * Configure the synchronous queue driver.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('queue.default', 'sync');
    }

    public function testFakeFor(): void
    {
        Queue::fakeFor(function (): void {
            Queue::push(new TestJob);
            Queue::assertPushed(TestJob::class);
        });
    }

    public function testFakeExceptFor(): void
    {
        Queue::fakeExceptFor(function (): void {
            Queue::push(new TestJob);
            Queue::push(new OtherTestJob);

            Queue::assertNotPushed(TestJob::class);
            Queue::assertPushed(OtherTestJob::class);
        }, [TestJob::class]);
    }

    public function testFakeExcept(): void
    {
        $fake = Queue::fakeExcept([TestJob::class]);

        $this->assertInstanceOf(QueueFake::class, $fake);
    }

    public function testFakeForReturnValue(): void
    {
        $result = Queue::fakeFor(function (): string {
            return 'test-value';
        });

        $this->assertSame('test-value', $result);
    }

    public function testFakeExceptForReturnValue(): void
    {
        $result = Queue::fakeExceptFor(function (): string {
            return 'test-value';
        }, []);

        $this->assertSame('test-value', $result);
    }

    public function testAssertPushedOnce(): void
    {
        Queue::fake();
        Queue::push(new TestJob);

        Queue::assertPushedOnce(TestJob::class);

        Queue::push(new TestJob);

        try {
            Queue::assertPushedOnce(TestJob::class);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString(
                'The expected [' . TestJob::class . '] job was pushed 2 times instead of 1 time.',
                $exception->getMessage()
            );
        }
    }
}

class TestJob
{
    use Queueable;

    /**
     * Handle the test job.
     */
    public function handle(): void
    {
    }
}

class OtherTestJob
{
    use Queueable;

    /**
     * Handle the test job.
     */
    public function handle(): void
    {
    }
}
