<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\AllTag\Add;
use Hypervel\Cache\Redis\Operations\AllTag\AddEntry;
use Hypervel\Cache\Redis\Operations\AllTag\Decrement;
use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Cache\Redis\Operations\AllTag\Forever;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Cache\Redis\Operations\AllTag\Increment;
use Hypervel\Cache\Redis\Operations\AllTag\Prune;
use Hypervel\Cache\Redis\Operations\AllTag\Put;
use Hypervel\Cache\Redis\Operations\AllTag\PutMany;
use Hypervel\Cache\Redis\Operations\AllTag\Remember;
use Hypervel\Cache\Redis\Operations\AllTag\RememberForever;
use Hypervel\Tests\Cache\Redis\Concerns\MocksRedisConnections;
use Hypervel\Tests\TestCase;

/**
 * Tests for the AllTagOperations container class.
 *
 * @internal
 * @coversNothing
 */
class AllTagOperationsTest extends TestCase
{
    use MocksRedisConnections;

    /**
     * @test
     */
    public function testAllOperationAccessorsReturnCorrectTypes(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->allTagOps();

        $this->assertInstanceOf(Put::class, $ops->put());
        $this->assertInstanceOf(PutMany::class, $ops->putMany());
        $this->assertInstanceOf(Add::class, $ops->add());
        $this->assertInstanceOf(Forever::class, $ops->forever());
        $this->assertInstanceOf(Increment::class, $ops->increment());
        $this->assertInstanceOf(Decrement::class, $ops->decrement());
        $this->assertInstanceOf(AddEntry::class, $ops->addEntry());
        $this->assertInstanceOf(GetEntries::class, $ops->getEntries());
        $this->assertInstanceOf(FlushStale::class, $ops->flushStale());
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
        $ops = $store->allTagOps();

        // Same instance returned on repeated calls
        $this->assertSame($ops->put(), $ops->put());
        $this->assertSame($ops->remember(), $ops->remember());
        $this->assertSame($ops->getEntries(), $ops->getEntries());
    }

    /**
     * @test
     */
    public function testClearResetsAllCachedInstances(): void
    {
        $connection = $this->mockConnection();
        $store = $this->createStore($connection);
        $ops = $store->allTagOps();

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
