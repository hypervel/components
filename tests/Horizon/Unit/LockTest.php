<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Redis\Factory;
use Hypervel\Horizon\Lock;
use Hypervel\Tests\Horizon\UnitTestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use RuntimeException;

class LockTest extends UnitTestCase
{
    public function testGetAcquiresTheLockWithOneAtomicCommand(): void
    {
        [$connection, $client] = $this->redisConnection();
        $client->expects('set')
            ->with('metrics', '1', ['NX', 'EX' => 60])
            ->returns(true);
        $client->expects('setNx')->never();
        $client->expects('expire')->never();

        $lock = new Lock($this->redisFactory($connection));

        $this->assertTrue($lock->get('metrics'));
    }

    public function testGetRejectsNonPositiveLifetimesBeforeConnecting(): void
    {
        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Horizon lock [metrics] requires a positive lifetime; 0 given.');

        (new Lock($redis))->get('metrics', 0);
    }

    public function testWithRejectsNonPositiveLifetimesBeforeConnecting(): void
    {
        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Horizon lock [metrics] requires a positive lifetime; -1 given.');

        (new Lock($redis))->with('metrics', static function (): void {
        }, -1);
    }

    public function testCallbackFailureRemainsPrimaryWhenOwnedReleaseFails(): void
    {
        $callbackFailure = new RuntimeException('callback failed');
        [$connection, $client, $rawConnection] = $this->redisConnection();
        $rawConnection->expects('pack')->returns(['packed-owner']);
        $client->expects('eval')->throws(new RuntimeException('release failed'));
        $client->expects('set')
            ->with('metrics', Argument::type('string'), ['NX', 'EX' => 60])
            ->returns(true);

        try {
            (new Lock($this->redisFactory($connection)))->with(
                'metrics',
                static fn () => throw $callbackFailure,
            );
            $this->fail('Expected the callback failure to propagate.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($callbackFailure, $thrown);
        }

        $connection->received('withConnection')->times(1);
    }
}
