<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\ForgetCommand;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ForgetCommandTest extends TestCase
{
    public function testForgetKeyFromDefaultStore()
    {
        $cacheManager = m::mock(CacheManager::class);
        $repository = m::mock(Repository::class);

        $cacheManager->shouldReceive('store')->once()->with(null)->andReturn($repository);
        $repository->shouldReceive('forget')->once()->with('my-key');

        $command = new ForgetCommand($cacheManager);
        $command->setHypervel(new Application);

        $output = new BufferedOutput;

        $this->assertSame(0, $command->run(new ArrayInput(['key' => 'my-key']), $output));
        $this->assertStringContainsString(
            'key has been removed from the cache',
            $output->fetch()
        );
    }

    public function testForgetKeyFromSpecifiedStore()
    {
        $cacheManager = m::mock(CacheManager::class);
        $repository = m::mock(Repository::class);

        $cacheManager->shouldReceive('store')->once()->with('redis')->andReturn($repository);
        $repository->shouldReceive('forget')->once()->with('my-key');

        $command = new ForgetCommand($cacheManager);
        $command->setHypervel(new Application);

        $output = new BufferedOutput;

        $this->assertSame(0, $command->run(new ArrayInput(['key' => 'my-key', 'store' => 'redis']), $output));
        $this->assertStringContainsString(
            'key has been removed from the cache',
            $output->fetch()
        );
    }
}
