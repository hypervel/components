<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\AnyTag\Add;
use Hypervel\Cache\Redis\Operations\AnyTag\Decrement;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\Forever;
use Hypervel\Cache\Redis\Operations\AnyTag\Forget;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTagItems;
use Hypervel\Cache\Redis\Operations\AnyTag\Increment;
use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Cache\Redis\Operations\AnyTag\Put;
use Hypervel\Cache\Redis\Operations\AnyTag\PutMany;
use Hypervel\Cache\Redis\Operations\AnyTag\Touch;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;

class AnyTagOperationsTest extends RedisCacheTestCase
{
    public function testAllOperationAccessorsReturnCorrectTypes(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->anyTagOps();

        $this->assertInstanceOf(Put::class, $ops->put());
        $this->assertInstanceOf(PutMany::class, $ops->putMany());
        $this->assertInstanceOf(Add::class, $ops->add());
        $this->assertInstanceOf(Forever::class, $ops->forever());
        $this->assertInstanceOf(Increment::class, $ops->increment());
        $this->assertInstanceOf(Decrement::class, $ops->decrement());
        $this->assertInstanceOf(Touch::class, $ops->touch());
        $this->assertInstanceOf(Forget::class, $ops->forget());
        $this->assertInstanceOf(GetTaggedKeys::class, $ops->getTaggedKeys());
        $this->assertInstanceOf(GetTagItems::class, $ops->getTagItems());
        $this->assertInstanceOf(Flush::class, $ops->flush());
        $this->assertInstanceOf(Prune::class, $ops->prune());
    }

    public function testOperationInstancesAreCached(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->anyTagOps();

        $this->assertSame($ops->put(), $ops->put());
        $this->assertSame($ops->getTaggedKeys(), $ops->getTaggedKeys());
    }
}
