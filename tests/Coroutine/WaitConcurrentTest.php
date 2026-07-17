<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Engine\Channel;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use RuntimeException;

class WaitConcurrentTest extends TestCase
{
    public function testForkParticipatesInWaitTracking(): void
    {
        $release = new Channel(1);
        $concurrent = new WaitConcurrent(1);

        $concurrent->fork(static function () use ($release): void {
            $release->pop();
        });

        $this->assertFalse($concurrent->wait(0.005));

        $release->push(true);

        $this->assertTrue($concurrent->wait(1.0));
        $this->assertTrue($concurrent->isEmpty());
    }

    public function testForkBalancesWaitTrackingWhenContextReplicationFails(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);
        $concurrent = new WaitConcurrent(1);

        try {
            $concurrent->fork(static function (): void {
                throw new RuntimeException('The child must not be created.');
            });
            $this->fail('Expected context replication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replicate context.', $exception->getMessage());
        }

        $this->assertTrue($concurrent->wait(0.005));
        $this->assertTrue($concurrent->isEmpty());
    }
}
