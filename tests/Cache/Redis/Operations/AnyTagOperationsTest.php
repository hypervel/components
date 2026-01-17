<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\AnyTag\Add;
use Hypervel\Cache\Redis\Operations\AnyTag\Decrement;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\Forever;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTagItems;
use Hypervel\Cache\Redis\Operations\AnyTag\Increment;
use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Cache\Redis\Operations\AnyTag\Put;
use Hypervel\Cache\Redis\Operations\AnyTag\PutMany;
use Hypervel\Cache\Redis\Operations\AnyTag\Remember;
use Hypervel\Cache\Redis\Operations\AnyTag\RememberForever;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;

/**
 * Tests for the AnyTagOperations container class.
 *
 * @internal
 * @coversNothing
 */
class AnyTagOperationsTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
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
        $this->assertInstanceOf(GetTaggedKeys::class, $ops->getTaggedKeys());
        $this->assertInstanceOf(GetTagItems::class, $ops->getTagItems());
        $this->assertInstanceOf(Flush::class, $ops->flush());
        $this->assertInstanceOf(Prune::class, $ops->prune());
        $this->assertInstanceOf(Remember::class, $ops->remember());
        $this->assertInstanceOf(RememberForever::class, $ops->rememberForever());
    }

    /**
     * @test
     */
    public function testOperationInstancesAreCached(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->anyTagOps();

        // Same instance returned on repeated calls
        $this->assertSame($ops->put(), $ops->put());
        $this->assertSame($ops->remember(), $ops->remember());
        $this->assertSame($ops->getTaggedKeys(), $ops->getTaggedKeys());
    }

    /**
     * @test
     */
    public function testClearResetsAllCachedInstances(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->anyTagOps();

        // Get instances before clear
        $putBefore = $ops->put();
        $rememberBefore = $ops->remember();

        // Clear
        $ops->clear();

        // Instances should be new after clear
        $this->assertNotSame($putBefore, $ops->put());
        $this->assertNotSame($rememberBefore, $ops->remember());
    }
}
