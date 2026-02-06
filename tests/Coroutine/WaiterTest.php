<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exception\WaitTimeoutException;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\wait;

/**
 * @internal
 * @coversNothing
 */
class WaiterTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        $container = m::mock(ContainerContract::class);
        ApplicationContext::setContainer($container);
        $container->shouldReceive('get')->with(Waiter::class)->andReturn(new Waiter());
    }

    public function testWait()
    {
        $id = uniqid();
        $result = wait(function () use ($id) {
            return $id;
        });

        $this->assertSame($id, $result);

        $id = rand(0, 9999);
        $result = wait(function () use ($id) {
            return $id + 1;
        });

        $this->assertSame($id + 1, $result);
    }

    public function testWaitNone()
    {
        $callback = function () {
        };
        $result = wait($callback);
        $this->assertSame($result, $callback());
        $this->assertSame(null, $result);

        $callback = function () {
            return null;
        };
        $result = wait($callback);
        $this->assertSame($result, $callback());
        $this->assertSame(null, $result);
    }

    public function testWaitException()
    {
        $message = uniqid();
        $callback = function () use ($message) {
            throw new RuntimeException($message);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        wait($callback);
    }

    public function testWaitReturnException()
    {
        $message = uniqid();
        $callback = function () use ($message) {
            return new RuntimeException($message);
        };

        $result = wait($callback);
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame($message, $result->getMessage());
    }

    public function testPushTimeout()
    {
        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 1));
        $this->assertSame(false, $channel->push(1, 1));
    }

    public function testTimeout()
    {
        $callback = function () {
            Coroutine::sleep(0.5);
            return true;
        };

        $this->expectException(WaitTimeoutException::class);
        $this->expectExceptionMessage('Channel wait failed, reason: Timed out for 0.001 s');
        wait($callback, 0.001);
    }
}
