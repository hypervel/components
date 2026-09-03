<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisManager;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

class RedisEventsIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.redis.default.events', false);
    }

    public function testEventOverrideRefreshesOnlyMismatchedExistingPoolGenerations(): void
    {
        $commands = [];
        $this->app->make(Dispatcher::class)->listen(
            CommandExecuted::class,
            static function (CommandExecuted $event) use (&$commands): void {
                $commands[] = $event->command;
            },
        );

        $manager = $this->app->make(RedisManager::class);
        $poolFactory = $this->app->make(PoolFactory::class);
        $initialPool = $poolFactory->getPool('default');

        Redis::ping();
        $this->assertSame([], $commands);

        $manager->enableEvents();
        $this->assertArrayNotHasKey('default', $poolFactory->pools());

        Redis::set('redis-events-integration', 'value');
        $enabledPool = $poolFactory->pools()['default'];
        $this->assertNotSame($initialPool, $enabledPool);
        $this->assertSame(['set'], $commands);

        $manager->enableEvents();
        $this->assertSame($enabledPool, $poolFactory->pools()['default']);

        Redis::get('redis-events-integration');
        $this->assertSame(['set', 'get'], $commands);

        $manager->disableEvents();
        $this->assertArrayNotHasKey('default', $poolFactory->pools());

        Redis::get('redis-events-integration');
        $disabledPool = $poolFactory->pools()['default'];
        $this->assertNotSame($enabledPool, $disabledPool);
        $this->assertSame(['set', 'get'], $commands);

        $manager->disableEvents();
        $this->assertSame($disabledPool, $poolFactory->pools()['default']);
    }
}
