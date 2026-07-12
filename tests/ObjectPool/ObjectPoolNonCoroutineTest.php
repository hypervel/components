<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Event;

class ObjectPoolNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testDeadlineReleaseRemainsCommittedWhenItsWakeCannotBeCreated(): void
    {
        $pool = $this->createPool();
        $borrowed = $pool->get();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
        });

        $pool->release($borrowed);
        Event::wait();

        $this->assertSame($borrowed, $replacement);
        $pool->release($replacement);
    }

    #[RunInSeparateProcess]
    public function testDeadlineDiscardRemainsCommittedWhenItsWakeCannotBeCreated(): void
    {
        $pool = $this->createPool();
        $borrowed = $pool->get();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
        });

        $pool->discard($borrowed);
        Event::wait();

        $this->assertInstanceOf(stdClass::class, $replacement);
        $this->assertNotSame($borrowed, $replacement);
        $pool->release($replacement);
    }

    private function createPool(): NonCoroutineObjectPool
    {
        $container = new Container;
        Container::setInstance($container);

        return new NonCoroutineObjectPool(
            $container,
            PoolOptions::fromArray([
                'max_objects' => 1,
                'wait_timeout' => 0.001,
            ]),
        );
    }
}

class NonCoroutineObjectPool extends ObjectPool
{
    protected function createObject(): object
    {
        return new stdClass;
    }
}
