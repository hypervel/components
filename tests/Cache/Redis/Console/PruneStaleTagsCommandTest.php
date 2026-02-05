<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Console;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Redis\Console\PruneStaleTagsCommand;
use Hypervel\Cache\Redis\Operations\AllTag\Prune as IntersectionPrune;
use Hypervel\Cache\Redis\Operations\AllTagOperations;
use Hypervel\Cache\Redis\Operations\AnyTag\Prune as UnionPrune;
use Hypervel\Cache\Redis\Operations\AnyTagOperations;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class PruneStaleTagsCommandTest extends TestCase
{
    public function testPruneAllModeCallsCorrectOperation(): void
    {
        $intersectionPrune = m::mock(IntersectionPrune::class);
        $intersectionPrune->shouldReceive('execute')
            ->once()
            ->andReturn([
                'tags_scanned' => 10,
                'entries_removed' => 5,
                'empty_sets_deleted' => 2,
            ]);

        $intersectionOps = m::mock(AllTagOperations::class);
        $intersectionOps->shouldReceive('prune')
            ->once()
            ->andReturn($intersectionPrune);

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')
            ->once()
            ->andReturn(TagMode::All);
        $store->shouldReceive('allTagOps')
            ->once()
            ->andReturn($intersectionOps);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->once()
            ->andReturn($repository);

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand();
        $command->run(new ArrayInput([]), new NullOutput());

        // Mockery will verify expectations in tearDown
    }

    public function testPruneAnyModeCallsCorrectOperation(): void
    {
        $unionPrune = m::mock(UnionPrune::class);
        $unionPrune->shouldReceive('execute')
            ->once()
            ->andReturn([
                'hashes_scanned' => 8,
                'fields_checked' => 100,
                'orphans_removed' => 15,
                'empty_hashes_deleted' => 3,
                'expired_tags_removed' => 2,
            ]);

        $unionOps = m::mock(AnyTagOperations::class);
        $unionOps->shouldReceive('prune')
            ->once()
            ->andReturn($unionPrune);

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')
            ->once()
            ->andReturn(TagMode::Any);
        $store->shouldReceive('anyTagOps')
            ->once()
            ->andReturn($unionOps);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('redis')
            ->once()
            ->andReturn($repository);

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand();
        $command->run(new ArrayInput([]), new NullOutput());

        // Mockery will verify expectations in tearDown
    }

    public function testPruneUsesSpecifiedStore(): void
    {
        $intersectionPrune = m::mock(IntersectionPrune::class);
        $intersectionPrune->shouldReceive('execute')
            ->once()
            ->andReturn([
                'tags_scanned' => 0,
                'entries_removed' => 0,
                'empty_sets_deleted' => 0,
            ]);

        $intersectionOps = m::mock(AllTagOperations::class);
        $intersectionOps->shouldReceive('prune')
            ->once()
            ->andReturn($intersectionPrune);

        $store = m::mock(RedisStore::class);
        $store->shouldReceive('getTagMode')
            ->once()
            ->andReturn(TagMode::All);
        $store->shouldReceive('allTagOps')
            ->once()
            ->andReturn($intersectionOps);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        // Should use the specified store name
        $cacheManager->shouldReceive('store')
            ->with('custom-redis')
            ->once()
            ->andReturn($repository);

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand();
        $command->run(new ArrayInput(['store' => 'custom-redis']), new NullOutput());

        // Mockery will verify expectations in tearDown
    }

    public function testPruneFailsForNonRedisStore(): void
    {
        $nonRedisStore = m::mock(Store::class);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($nonRedisStore);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('file')
            ->once()
            ->andReturn($repository);

        $this->app->set(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand();
        $result = $command->run(new ArrayInput(['store' => 'file']), new NullOutput());

        // Should return failure code for non-Redis store
        $this->assertSame(1, $result);
    }
}
