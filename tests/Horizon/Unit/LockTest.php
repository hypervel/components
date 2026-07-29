<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Lock;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

class LockTest extends UnitTestCase
{
    public function testGetAcquiresTheLockWithOneAtomicCommand(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('set')
            ->once()
            ->with('metrics', '1', 'EX', 60, 'NX')
            ->andReturnTrue();
        $connection->shouldNotReceive('setNx');
        $connection->shouldNotReceive('expire');

        $lock = new Lock($this->redisFactory($connection));

        $this->assertTrue($lock->get('metrics'));
    }

    public function testGetRejectsNonPositiveLifetimesBeforeConnecting(): void
    {
        $redis = m::mock(Factory::class);
        $redis->shouldNotReceive('connection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Horizon lock [metrics] requires a positive lifetime; 0 given.');

        (new Lock($redis))->get('metrics', 0);
    }

    public function testWithRejectsNonPositiveLifetimesBeforeConnecting(): void
    {
        $redis = m::mock(Factory::class);
        $redis->shouldNotReceive('connection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Horizon lock [metrics] requires a positive lifetime; -1 given.');

        (new Lock($redis))->with('metrics', static function (): void {
        }, -1);
    }

    public function testCallbackFailureRemainsPrimaryWhenOwnedReleaseFails(): void
    {
        $callbackFailure = new RuntimeException('callback failed');
        $rawConnection = m::mock(RedisConnection::class);
        $rawConnection->shouldReceive('pack')->once()->andReturn(['packed-owner']);
        $rawConnection->shouldReceive('eval')->once()->andThrow(new RuntimeException('release failed'));

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('set')
            ->once()
            ->with('metrics', m::type('string'), 'EX', 60, 'NX')
            ->andReturnTrue();
        $connection->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($rawConnection));

        try {
            (new Lock($this->redisFactory($connection)))->with(
                'metrics',
                static fn () => throw $callbackFailure,
            );
            $this->fail('Expected the callback failure to propagate.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($callbackFailure, $thrown);
        }
    }
}
