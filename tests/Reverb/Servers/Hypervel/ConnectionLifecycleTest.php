<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\go;

class ConnectionLifecycleTest extends TestCase
{
    public function testItAttachesOneConnection(): void
    {
        $lifecycle = new ConnectionLifecycle(123);
        $connection = m::mock(Connection::class);

        $lifecycle->attach($connection);

        $this->assertSame(123, $lifecycle->fd);
        $this->assertSame($connection, $lifecycle->connection());

        $this->expectException(LogicException::class);

        $lifecycle->attach(m::mock(Connection::class));
    }

    public function testTerminalCloseWaitsForCurrentWorkAndRejectsCapturedWaiters(): void
    {
        $lifecycle = new ConnectionLifecycle(123);
        $operationStarted = new Channel(1);
        $releaseOperation = new Channel(1);
        $terminalStarted = new Channel(1);
        $waiterFinished = new Channel(1);
        $closeFinished = new Channel(1);
        $waiterRan = false;

        go(function () use ($lifecycle, $operationStarted, $releaseOperation): void {
            $lifecycle->run(function () use ($operationStarted, $releaseOperation): void {
                $operationStarted->push(true);
                $releaseOperation->pop();
            });
        });

        $operationStarted->pop();

        go(function () use ($lifecycle, $terminalStarted, $closeFinished): void {
            $lifecycle->close(function () use ($terminalStarted): void {
                $terminalStarted->push(true);
            });
            $closeFinished->push(true);
        });

        go(function () use ($lifecycle, $waiterFinished, &$waiterRan): void {
            $lifecycle->run(function () use (&$waiterRan): void {
                $waiterRan = true;
            });
            $waiterFinished->push(true);
        });

        $this->assertFalse($terminalStarted->pop(0.01));

        $releaseOperation->push(true);

        $this->assertTrue($terminalStarted->pop(1));
        $this->assertTrue($closeFinished->pop(1));
        $this->assertTrue($waiterFinished->pop(1));
        $this->assertFalse($waiterRan);
        $this->assertNull($lifecycle->run(static fn (): bool => true));
    }

    public function testDifferentConnectionLifecyclesDoNotContend(): void
    {
        $first = new ConnectionLifecycle(1);
        $second = new ConnectionLifecycle(2);
        $firstStarted = new Channel(1);
        $releaseFirst = new Channel(1);
        $firstFinished = new Channel(1);
        $secondFinished = new Channel(1);

        go(function () use ($first, $firstStarted, $releaseFirst, $firstFinished): void {
            $first->run(function () use ($firstStarted, $releaseFirst): void {
                $firstStarted->push(true);
                $releaseFirst->pop();
            });
            $firstFinished->push(true);
        });

        $firstStarted->pop();

        go(function () use ($second, $secondFinished): void {
            $second->run(static fn (): bool => $secondFinished->push(true));
        });

        $this->assertTrue($secondFinished->pop(1));

        $releaseFirst->push(true);
        $this->assertTrue($firstFinished->pop(1));
    }
}
