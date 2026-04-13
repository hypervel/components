<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\QueueFakeTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class QueueFakeTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    public function testFakeFor()
    {
        Queue::fakeFor(function () {
            Queue::push(new TestJob);
            Queue::assertPushed(TestJob::class);
        });
    }

    public function testFakeExceptFor()
    {
        Queue::fakeExceptFor(function () {
            Queue::push(new TestJob);
            Queue::push(new OtherTestJob);

            Queue::assertNotPushed(TestJob::class);
            Queue::assertPushed(OtherTestJob::class);
        }, [TestJob::class]);
    }

    public function testFakeExcept()
    {
        $fake = Queue::fakeExcept([TestJob::class]);

        $this->assertInstanceOf(QueueFake::class, $fake);
    }

    public function testFakeForReturnValue()
    {
        $result = Queue::fakeFor(function () {
            return 'test-value';
        });

        $this->assertEquals('test-value', $result);
    }

    public function testFakeExceptForReturnValue()
    {
        $result = Queue::fakeExceptFor(function () {
            return 'test-value';
        }, []);

        $this->assertEquals('test-value', $result);
    }
}

class TestJob
{
    use Queueable;

    public function handle()
    {
    }
}

class OtherTestJob
{
    use Queueable;

    public function handle()
    {
    }
}
