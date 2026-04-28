<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Events;

use Exception;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CommandExecutedTest extends TestCase
{
    public function testCommandExecutedConstructor()
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn('default');

        $event = new CommandExecuted('GET', ['key1'], 0.1, $connection);

        $this->assertSame('GET', $event->command);
        $this->assertSame(['key1'], $event->parameters);
        $this->assertSame(0.1, $event->time);
        $this->assertSame($connection, $event->connection);
        $this->assertSame('default', $event->connectionName);
    }

    public function testCommandExecutedConnectionNameIsDerivedFromConnection()
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->once()->andReturn('cache');

        $event = new CommandExecuted('SET', ['key', 'value'], 0.5, $connection);

        $this->assertSame('cache', $event->connectionName);
    }

    public function testCommandFailedConstructor()
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn('default');
        $exception = new Exception('Redis error');

        $event = new CommandFailed('GET', ['key1'], $exception, $connection);

        $this->assertSame('GET', $event->command);
        $this->assertSame(['key1'], $event->parameters);
        $this->assertSame($exception, $event->exception);
        $this->assertSame($connection, $event->connection);
        $this->assertSame('default', $event->connectionName);
        $this->assertNull($event->time);
    }

    public function testCommandFailedWithTime()
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn('default');
        $exception = new Exception('Redis error');

        $event = new CommandFailed('GET', ['key1'], $exception, $connection, 1.5);

        $this->assertSame($exception, $event->exception);
        $this->assertSame(1.5, $event->time);
    }
}
