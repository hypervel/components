<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coroutine\Concurrent;
use Mockery;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class ConcurrentTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContainer();
    }

    public function testConcurrent(): void
    {
        $concurrent = new Concurrent($limit = 10);
        $this->assertSame($limit, $concurrent->getLimit());
        $this->assertTrue($concurrent->isEmpty());
        $this->assertFalse($concurrent->isFull());

        $count = 0;
        for ($i = 0; $i < 15; ++$i) {
            $concurrent->create(function () use (&$count) {
                Coroutine::sleep(0.1);
                ++$count;
            });
        }

        $this->assertTrue($concurrent->isFull());
        $this->assertSame(5, $count);
        $this->assertSame($limit, $concurrent->getRunningCoroutineCount());
        $this->assertSame($limit, $concurrent->getLength());
        $this->assertSame($limit, $concurrent->length());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.1);
        }

        $this->assertSame(15, $count);
    }

    public function testException(): void
    {
        $con = new Concurrent(10);
        $count = 0;

        for ($i = 0; $i < 15; ++$i) {
            $con->create(function () use (&$count) {
                Coroutine::sleep(0.1);
                ++$count;
                throw new Exception('ddd');
            });
        }

        $this->assertSame(5, $count);
        $this->assertSame(10, $con->getRunningCoroutineCount());

        while (! $con->isEmpty()) {
            Coroutine::sleep(0.1);
        }
        $this->assertSame(15, $count);
    }

    protected function getContainer(): void
    {
        $container = Mockery::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);

        ApplicationContext::setContainer($container);
    }
}
