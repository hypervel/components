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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PruneStaleTagsCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testPruneCallsFlushStaleTagsOnStore(): void
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('flushStaleTags')
            ->returns([
                'tags_scanned' => 10,
                'entries_removed' => 5,
                'empty_sets_deleted' => 2,
            ]);

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('getStore')
            ->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with(null)
            ->returns($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testPruneUsesSpecifiedStore(): void
    {
        $store = Double::for(RedisStore::class)->strict();
        $store->expects('flushStaleTags')
            ->returns([
                'tags_scanned' => 0,
                'entries_removed' => 0,
                'empty_sets_deleted' => 0,
            ]);

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('getStore')
            ->returns($store);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with('custom-redis')
            ->returns($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['store' => 'custom-redis']), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testPruneHandlesNonSupportedStoreGracefully(): void
    {
        $nonRedisStore = Double::for(Store::class)->strict();

        $repository = Double::for(Repository::class)->strict();
        $repository->expects('getStore')
            ->returns($nonRedisStore);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with('file')
            ->returns($repository);

        $this->app->instance(CacheContract::class, $cacheManager);

        $command = new PruneStaleTagsCommand;
        $command->setHypervel($this->app);
        $result = $command->run(new ArrayInput(['store' => 'file']), new NullOutput);

        $this->assertSame(0, $result);
    }
}
