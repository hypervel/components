<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\PruneStaleTagsCommand;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PruneStaleTagsCommandTest extends TestCase
{
    public function testPruneCallsFlushStaleTagsOnStore()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('flushStaleTags')
            ->once()
            ->andReturn([
                'tags_scanned' => 10,
                'entries_removed' => 5,
                'empty_sets_deleted' => 2,
            ]);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with(null)
            ->once()
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testPruneUsesSpecifiedStore()
    {
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('flushStaleTags')
            ->once()
            ->andReturn([
                'tags_scanned' => 0,
                'entries_removed' => 0,
                'empty_sets_deleted' => 0,
            ]);

        $repository = m::mock(Repository::class);
        $repository->shouldReceive('getStore')
            ->once()
            ->andReturn($store);

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('custom-redis')
            ->once()
            ->andReturn($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['store' => 'custom-redis']), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testPruneHandlesNonSupportedStoreGracefully()
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

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['store' => 'file']), new NullOutput);

        $this->assertSame(0, $result);
    }
}
