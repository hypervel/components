<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\RedisConnection;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\RedisWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class RedisWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get('config')
            ->set('telescope.watchers', [
                RedisWatcher::class => true,
            ]);
        $this->app->get('config')
            ->set('database.redis.foo', [
                'host' => '127.0.0.1',
                'port' => 6379,
                'db' => 0,
            ]);

        RedisWatcher::enableRedisEvents($this->app);

        $this->startTelescope();
    }

    public function testRegisterEnableRedisEvents()
    {
        $this->assertTrue(
            $this->app->get('config')
                ->get('database.redis.foo.event.enable', false)
        );
    }

    public function testRedisWatcherRegistersEntries()
    {
        $this->app->get(Dispatcher::class)
            ->dispatch(new CommandExecuted(
                'command',
                ['foo', 'bar'],
                0.0123,
                m::mock(RedisConnection::class),
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
}
