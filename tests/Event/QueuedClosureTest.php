<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Event\QueuedClosure;
use PHPUnit\Framework\TestCase;

enum QueuedClosureTestConnectionStringEnum: string
{
    case Redis = 'redis';
    case Sqs = 'sqs';
}

enum QueuedClosureTestConnectionIntEnum: int
{
    case Connection1 = 1;
    case Connection2 = 2;
}

enum QueuedClosureTestConnectionUnitEnum
{
    case sync;
    case database;
}

/**
 * @internal
 * @coversNothing
 */
class QueuedClosureTest extends TestCase
{
    public function testOnConnectionAcceptsStringBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onConnection(QueuedClosureTestConnectionStringEnum::Redis);

        $this->assertSame('redis', $closure->connection);
    }

    public function testOnConnectionAcceptsUnitEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onConnection(QueuedClosureTestConnectionUnitEnum::sync);

        $this->assertSame('sync', $closure->connection);
    }

    public function testOnConnectionAcceptsIntBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onConnection(QueuedClosureTestConnectionIntEnum::Connection1);

        // Int value 1 should be cast to string '1'
        $this->assertSame('1', $closure->connection);
    }

    public function testOnConnectionAcceptsNull(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onConnection(null);

        $this->assertNull($closure->connection);
    }

    public function testOnQueueAcceptsStringBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onQueue(QueuedClosureTestConnectionStringEnum::Sqs);

        $this->assertSame('sqs', $closure->queue);
    }

    public function testOnQueueAcceptsUnitEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onQueue(QueuedClosureTestConnectionUnitEnum::database);

        $this->assertSame('database', $closure->queue);
    }

    public function testOnQueueAcceptsIntBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onQueue(QueuedClosureTestConnectionIntEnum::Connection2);

        // Int value 2 should be cast to string '2'
        $this->assertSame('2', $closure->queue);
    }

    public function testOnQueueAcceptsNull(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onQueue(null);

        $this->assertNull($closure->queue);
    }

    public function testOnGroupAcceptsStringBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onGroup(QueuedClosureTestConnectionStringEnum::Redis);

        $this->assertSame('redis', $closure->messageGroup);
    }

    public function testOnGroupAcceptsUnitEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onGroup(QueuedClosureTestConnectionUnitEnum::sync);

        $this->assertSame('sync', $closure->messageGroup);
    }

    public function testOnGroupAcceptsIntBackedEnum(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure->onGroup(QueuedClosureTestConnectionIntEnum::Connection1);

        // Int value 1 should be cast to string '1'
        $this->assertSame('1', $closure->messageGroup);
    }

    public function testOnQueueSetsQueueProperty(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $result = $closure->onQueue('high-priority');

        $this->assertSame('high-priority', $closure->queue);
        $this->assertSame($closure, $result); // Returns self for chaining
    }

    public function testOnGroupSetsMessageGroupProperty(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $result = $closure->onGroup('my-group');

        $this->assertSame('my-group', $closure->messageGroup);
        $this->assertSame($closure, $result); // Returns self for chaining
    }

    public function testMethodsAreChainable(): void
    {
        $closure = new QueuedClosure(fn () => null);

        $closure
            ->onConnection('redis')
            ->onQueue('emails')
            ->onGroup('group-1')
            ->delay(60);

        $this->assertSame('redis', $closure->connection);
        $this->assertSame('emails', $closure->queue);
        $this->assertSame('group-1', $closure->messageGroup);
        $this->assertSame(60, $closure->delay);
    }
}
