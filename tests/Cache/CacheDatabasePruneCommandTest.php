<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hyperf\Command\Command;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\PruneDbExpiredCommand;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\Repository;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class CacheDatabasePruneCommandTest extends TestCase
{
    public function testPruneCommandRemovesExpiredEntries()
    {
        $app = m::mock(ApplicationInterface::class);
        $cacheManager = m::mock(CacheManager::class);
        $repository = m::mock(Repository::class);
        $store = m::mock(DatabaseStore::class);
        
        $app->shouldReceive('get')->with(CacheManager::class)->andReturn($cacheManager);
        $cacheManager->shouldReceive('store')->with(null)->andReturn($repository);
        $repository->shouldReceive('getStore')->andReturn($store);
        $store->shouldReceive('pruneExpired')->once()->andReturn(42);
        
        $command = new PruneDbExpiredCommand();
        $command->setContainer($app);
        
        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $result = $command->run($input, $output);
        
        $this->assertEquals(0, $result);
    }

    public function testPruneCommandFailsOnNonDatabaseStore()
    {
        $app = m::mock(ApplicationInterface::class);
        $cacheManager = m::mock(CacheManager::class);
        $repository = m::mock(Repository::class);
        $store = m::mock(\Hypervel\Cache\ArrayStore::class);
        
        $app->shouldReceive('get')->with(CacheManager::class)->andReturn($cacheManager);
        $cacheManager->shouldReceive('store')->with(null)->andReturn($repository);
        $repository->shouldReceive('getStore')->andReturn($store);
        
        $command = new PruneDbExpiredCommand();
        $command->setContainer($app);
        
        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $result = $command->run($input, $output);
        
        $this->assertEquals(1, $result);
    }

    public function testPruneCommandWithSpecificStore()
    {
        $app = m::mock(ApplicationInterface::class);
        $cacheManager = m::mock(CacheManager::class);
        $repository = m::mock(Repository::class);
        $store = m::mock(DatabaseStore::class);
        
        $app->shouldReceive('get')->with(CacheManager::class)->andReturn($cacheManager);
        $cacheManager->shouldReceive('store')->with('database')->andReturn($repository);
        $repository->shouldReceive('getStore')->andReturn($store);
        $store->shouldReceive('pruneExpired')->once()->andReturn(10);
        
        $command = new PruneDbExpiredCommand();
        $command->setContainer($app);
        
        $input = new ArrayInput(['store' => 'database']);
        $output = new NullOutput();
        
        $result = $command->run($input, $output);
        
        $this->assertEquals(0, $result);
    }
}