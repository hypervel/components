<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConfig;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\RedisWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use JsonSerializable;
use Mockery as m;
use ReflectionClass;
use RuntimeException;

#[WithConfig('telescope.watchers', [
    RedisWatcher::class => true,
])]
#[WithConfig('database.redis.foo', [
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
])]
class RedisWatcherTest extends FeatureTestCase
{
    public function testRegisterEnablesRedisEventsForFuturePools(): void
    {
        $this->assertTrue(
            $this->app->make(RedisConfig::class)
                ->connectionConfig('foo')['event']['enable']
        );
    }

    public function testFlushStateDisablesRedisEvents(): void
    {
        RedisWatcher::enableRedisEvents($this->app);
        $this->assertTrue($this->eventsAreEnabled());

        RedisWatcher::flushState();

        $this->assertFalse($this->eventsAreEnabled());
    }

    public function testRedisWatcherRegistersEntries(): void
    {
        $this->dispatchRedisCommand('command', ['foo', 'bar']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REDIS, $entry->type);
        $this->assertSame('command foo bar', $entry->content['command']);
        $this->assertSame('connection', $entry->content['connection']);
        $this->assertSame('0.01', $entry->content['time']);
    }

    public function testRedisWatcherFormatsNestedScalarArrays(): void
    {
        $this->dispatchRedisCommand('command', [
            ['first', 'named' => 'second', 'nested' => ['third', 'fourth']],
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(
            'command first named second nested third fourth',
            $entry->content['command'],
        );
    }

    public function testRedisWatcherFormatsNonScalarParametersWithoutInvokingUserCode(): void
    {
        $stream = fopen('php://memory', 'r+');
        $json = new RedisWatcherThrowingJsonSerializable;

        try {
            $this->dispatchRedisCommand('command', [
                $json,
                ['nested' => $json],
                static function (): void {},
                $stream,
            ]);
        } finally {
            fclose($stream);
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(
            'command '
            . RedisWatcherThrowingJsonSerializable::class
            . ' nested '
            . RedisWatcherThrowingJsonSerializable::class
            . ' Closure resource (stream)',
            $entry->content['command'],
        );
    }

    public function testRedisWatcherIgnoresBatchOpenersCaseInsensitively(): void
    {
        $this->dispatchRedisCommand('PiPeLiNe');
        $this->dispatchRedisCommand('MuLtI');
        $this->dispatchRedisCommand('GeT', ['key']);

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('GeT key', $entries->first()->content['command']);
    }

    public function testDoesNotRegisterWhenRedisUnbound(): void
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

    private function dispatchRedisCommand(string $command, array $parameters = []): void
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn('connection');

        $this->app->make(Dispatcher::class)
            ->dispatch(new CommandExecuted(
                $command,
                $parameters,
                0.0123,
                $connection,
            ));
    }

    private function eventsAreEnabled(): bool
    {
        return (new ReflectionClass(RedisWatcher::class))->getStaticPropertyValue('eventsEnabled');
    }
}

class RedisWatcherThrowingJsonSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('The formatter must not invoke user code.');
    }
}
