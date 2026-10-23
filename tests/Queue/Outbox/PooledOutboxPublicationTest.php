<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Contracts\Queue\PublishesOutboxMessages;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class PooledOutboxPublicationTest extends TestCase
{
    public function testNativeBatchCapabilityUsesOneLeaseAndRetainsItsResult(): void
    {
        $queue = m::mock(SyncQueue::class . ', ' . PublishesOutboxMessages::class)->makePartial();
        $messages = [$this->message(1), $this->message(2)];
        $expected = new OutboxBatchResult([1], failure: new RuntimeException('Partial rejection.'));
        $queue->expects('publishOutboxMessages')->with($messages)->andReturn($expected);
        $released = 0;
        $pools = new PoolManager;
        $proxy = $this->proxy($queue, $pools, $released);

        try {
            $this->assertSame($expected, $proxy->publishOutboxMessages($messages));
            $this->assertSame(1, $released);
            $this->assertSame(0, $pools->get($proxy->getPoolName())->getBorrowedCount());
        } finally {
            $pools->purgeAll();
        }
    }

    public function testSequentialFallbackRetainsConfirmedPrefixAndDefersFutureRows(): void
    {
        CarbonImmutable::setTestNow('2040-01-01 12:00:00');
        $queue = m::mock(SyncQueue::class)->makePartial();
        $messages = [$this->message(1), $this->message(2, 60), $this->message(3), $this->message(4), $this->message(5, 120)];
        $failure = new RuntimeException('Publication outcome unknown.');
        $queue->expects('pushRaw')->with($messages[0]->payload, 'orders', $messages[0]->options)->andReturn('accepted');
        $queue->expects('pushRaw')->with($messages[2]->payload, 'orders', $messages[2]->options)->andThrow($failure);
        $released = 0;
        $pools = new PoolManager;
        $proxy = $this->proxy($queue, $pools, $released);

        try {
            $result = $proxy->publishOutboxMessages($messages);

            $this->assertSame([1], $result->accepted);
            $this->assertSame([2, 5], $result->deferred);
            $this->assertSame($failure, $result->failure);
            $this->assertSame(1, $released);
            $this->assertSame(0, $pools->get($proxy->getPoolName())->getBorrowedCount());
        } finally {
            $pools->purgeAll();
        }
    }

    public function testCancellationReleasesTheBorrowedTransportAndRemainsCancellation(): void
    {
        $queue = m::mock(SyncQueue::class)->makePartial();
        $cancellation = new CanceledException;
        $queue->expects('pushRaw')->andThrow($cancellation);
        $released = 0;
        $pools = new PoolManager;
        $proxy = $this->proxy($queue, $pools, $released);

        try {
            try {
                $proxy->publishOutboxMessages([$this->message(1)]);
                $this->fail('Expected publication to remain cancelled.');
            } catch (CanceledException $exception) {
                $this->assertSame($cancellation, $exception);
            }

            $this->assertSame(1, $released);
            $this->assertSame(0, $pools->get($proxy->getPoolName())->getBorrowedCount());
        } finally {
            $pools->purgeAll();
        }
    }

    /**
     * Create a single-slot proxy with observable release and no external transport.
     */
    protected function proxy(SyncQueue $queue, PoolManager $pools, int &$released): QueuePoolProxy
    {
        return new QueuePoolProxy(
            new PoolDefinition('outbox-publication', 'queue', 'outbox-test', PoolOptions::fromArray(['max_objects' => 1])),
            static fn (): SyncQueue => $queue,
            $pools,
            static function () use (&$released): void {
                ++$released;
            },
        );
    }

    /**
     * Build an opaque message passed through the generic raw contract.
     */
    protected function message(int $id, ?int $delay = null): OutboxMessage
    {
        $uuid = (string) Str::uuid();

        return new OutboxMessage(
            $id,
            $uuid,
            (string) Str::uuid(),
            1,
            'custom',
            'orders',
            json_encode(['uuid' => $uuid]),
            ['transport' => ['custom' => ['key' => 'value']]],
            $delay === null ? null : CarbonImmutable::now()->getTimestamp() + $delay,
            1,
            1,
        );
    }
}
