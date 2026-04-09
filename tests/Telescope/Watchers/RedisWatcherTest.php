<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\RedisWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    RedisWatcher::class => true,
])]
#[WithConfig('database.redis.foo', [
    'host' => '127.0.0.1',
    'port' => 6379,
    'db' => 0,
])]
class RedisWatcherTest extends FeatureTestCase
{
    public function testRegisterEnableRedisEvents()
    {
        $this->assertTrue(
            $this->app->make('config')
                ->get('database.redis.foo.event.enable', false)
        );
    }

    public function testRedisWatcherRegistersEntries()
    {
        $this->app->make(Dispatcher::class)
            ->dispatch(new CommandExecuted(
                'command',
                ['foo', 'bar'],
                0.0123,
                m::mock(PhpRedisConnection::class),
                'connection',
                'result',
                null
            ));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REDIS, $entry->type);
        $this->assertSame('command foo bar', $entry->content['command']);
        $this->assertSame('connection', $entry->content['connection']);
        $this->assertSame('0.01', $entry->content['time']);
    }

    public function testDoesNotRegisterWhenRedisUnbound()
    {
        $app = m::mock(Application::class);

        $app->makePartial();

        $app->expects('bound')
            ->with('redis')
            ->andReturn(false);

        $app->shouldNotReceive('make')
            ->with('redis');

        $watcher = new RedisWatcher([]);

        $watcher->register($app);
    }
}
