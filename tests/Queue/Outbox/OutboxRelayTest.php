<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\PublishesOutboxMessages;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Events\OutboxBatchDelivered;
use Hypervel\Queue\Events\OutboxBatchDeliveryFailed;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\Outbox\OutboxRelay;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class OutboxRelayTest extends TestCase
{
    public function testClaimPublishSettleAndObserversReleaseTheirResourcesInOrder(): void
    {
        CarbonImmutable::setTestNow('2040-01-01 12:00:00');
        CoroutineContext::set('request-only', true);
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class . ', ' . PublishesOutboxMessages::class);
        $events = new Dispatcher;
        $exceptions = m::mock(ExceptionHandler::class);
        $messages = [$this->message(1)];
        $phases = [];
        $repository->expects('claim')->with('primary', 100)->andReturnUsing(function () use (&$phases, $messages): array {
            $this->assertFalse(CoroutineContext::has('request-only'));
            $phases[] = 'claim';
            Coroutine::defer(static function () use (&$phases): void { $phases[] = 'claim released'; });

            return $messages;
        });
        $queues->expects('connection')->with('redis')->andReturn($queue);
        $queue->expects('publishOutboxMessages')->with($messages)->andReturnUsing(function () use (&$phases): OutboxBatchResult {
            $this->assertSame(['claim', 'claim released'], $phases);
            $this->assertFalse(CoroutineContext::has('request-only'));
            $phases[] = 'publish';
            Coroutine::defer(static function () use (&$phases): void { $phases[] = 'publish released'; });

            // Drivers may preserve keys when filtering a batch's row IDs.
            return new OutboxBatchResult([7 => 1]);
        });
        $repository->expects('settle')->andReturnUsing(function () use (&$phases): int {
            $this->assertSame('publish released', end($phases));
            $phases[] = 'settle';
            Coroutine::defer(static function () use (&$phases): void { $phases[] = 'settle released'; });

            return 1;
        });
        $events->listen(OutboxBatchDelivered::class, function (OutboxBatchDelivered $event) use (&$phases): void {
            $this->assertSame('settle released', end($phases));
            $this->assertFalse(CoroutineContext::has('request-only'));
            $this->assertSame('primary', $event->database);
            $this->assertSame(1, $event->accepted);
            $this->assertSame(20, $event->oldestEligibleAge);
            Coroutine::defer(static function () use (&$phases): void { $phases[] = 'observer released'; });
        });
        $relay = new OutboxRelay($repository, $queues, $events, $exceptions);

        $this->assertTrue($relay->deliver('primary', $relay->claim('primary', 100)));
        $this->assertSame('observer released', end($phases));
    }

    public function testPartialResultsSettleBeforeAnotherConnectionAndReportingCannotBlockItsPublication(): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $first = m::mock(Queue::class . ', ' . PublishesOutboxMessages::class);
        $second = m::mock(Queue::class . ', ' . PublishesOutboxMessages::class);
        $events = new Dispatcher;
        $exceptions = m::mock(ExceptionHandler::class);
        $messages = [$this->message(1), $this->message(2), $this->message(3, 'other')];
        $failure = new RuntimeException('Second publication rejected.');
        $settled = [];
        $queues->expects('connection')->with('redis')->andReturn($first);
        $queues->expects('connection')->with('other')->andReturn($second);
        $first->expects('publishOutboxMessages')->andReturn(new OutboxBatchResult([1], failure: $failure));
        $second->expects('publishOutboxMessages')->andReturnUsing(function () use (&$settled): OutboxBatchResult {
            $this->assertSame([[1]], $settled);

            return new OutboxBatchResult([3]);
        });
        $repository->shouldReceive('settle')->twice()->andReturnUsing(function (string $database, array $group, OutboxBatchResult $result) use (&$settled): int {
            $settled[] = $result->accepted;

            return count($group);
        });
        $events->listen(OutboxBatchDeliveryFailed::class, function (OutboxBatchDeliveryFailed $event) use ($failure): void {
            $this->assertSame(1, $event->accepted);
            $this->assertSame(1, $event->failed);
            $this->assertSame($failure, $event->exception);
        });
        $exceptions->expects('report')->with($failure, m::on(function (array $context) use (&$settled): bool {
            $this->assertSame([[1], [3]], $settled);
            $this->assertSame([1, 2], $context['outbox_ids']);
            $this->assertSame(['SendInvoice', 'SendInvoice'], $context['jobs']);

            return true;
        }));

        $this->assertFalse((new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', $messages));
    }

    #[DataProvider('invalidResults')]
    public function testInvalidResultsReleaseEveryInputAsFailure(OutboxBatchResult $invalid): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class . ', ' . PublishesOutboxMessages::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $queues->expects('connection')->andReturn($queue);
        $queue->expects('publishOutboxMessages')->andReturn($invalid);
        $repository->expects('settle')->with('primary', m::type('array'), m::on(function (OutboxBatchResult $result): bool {
            $this->assertSame([], $result->accepted);
            $this->assertSame([], $result->deferred);
            $this->assertInstanceOf(LogicException::class, $result->failure);

            return true;
        }))->andReturn(2);
        $exceptions->expects('report')->with(m::type(LogicException::class), m::type('array'));

        $this->assertFalse((new OutboxRelay($repository, $queues, new Dispatcher, $exceptions))->deliver('primary', [$this->message(1), $this->message(2)]));
    }

    /**
     * Provide invalid driver accounting without repeating transport tests.
     */
    public static function invalidResults(): array
    {
        return [
            'foreign ID' => [new OutboxBatchResult([1, 3])],
            'duplicate ID' => [new OutboxBatchResult([1, 1])],
            'overlap' => [new OutboxBatchResult([1, 2], [2])],
            'missing row without failure' => [new OutboxBatchResult([1])],
            'non-integer ID' => [new OutboxBatchResult(['1', 2])],
            'defer immediate job' => [new OutboxBatchResult([1], [2])],
        ];
    }

    public function testGenericFallbackDefersFutureRowsWithoutEventsAndSettlesThemNormally(): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $events = new Dispatcher;
        $events->listen(OutboxBatchDelivered::class, fn () => $this->fail('Deferred rows were not delivered.'));
        $events->listen(OutboxBatchDeliveryFailed::class, fn () => $this->fail('Deferral is not failure.'));
        $queues->expects('connection')->andReturn($queue);
        $repository->expects('settle')->with('primary', m::type('array'), m::on(fn (OutboxBatchResult $result): bool => $result->accepted === [] && $result->deferred === [1] && $result->failure === null))->andReturn(1);

        $this->assertTrue((new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', [$this->message(1, deliverAt: CarbonImmutable::now()->getTimestamp() + 60)]));
    }

    public function testPublicationAndSettlementErrorsAreBothReportedWithPublicationOnTheEvent(): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $events = new Dispatcher;
        $publication = new RuntimeException('Broker unavailable.');
        $settlement = new RuntimeException('Database unavailable.');
        $queues->expects('connection')->andReturn($queue);
        $queue->expects('pushRaw')->andThrow($publication);
        $repository->expects('settle')->andThrow($settlement);
        $exceptions->expects('report')->with($publication, m::type('array'));
        $exceptions->expects('report')->with($settlement, m::type('array'));
        $events->listen(OutboxBatchDeliveryFailed::class, function (OutboxBatchDeliveryFailed $event) use ($publication): void {
            $this->assertSame($publication, $event->exception);
        });

        $this->assertFalse((new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', [$this->message(1)]));
    }

    public function testLostSettlementOwnershipReportsConfirmedDeliveryWithoutRepublishing(): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $events = new Dispatcher;
        $queues->expects('connection')->andReturn($queue);
        $queue->expects('pushRaw')->andReturn('accepted');
        $repository->expects('settle')->andReturn(0);
        $events->listen(OutboxBatchDelivered::class, fn () => $this->fail('Lost ownership is a settlement failure.'));
        $events->listen(OutboxBatchDeliveryFailed::class, function (OutboxBatchDeliveryFailed $event): void {
            $this->assertSame(1, $event->accepted);
            $this->assertSame(0, $event->failed);
            $this->assertStringContainsString('lease ownership changed', $event->exception->getMessage());
        });
        $exceptions->expects('report')->with(m::type(RuntimeException::class), m::type('array'));

        $this->assertFalse((new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', [$this->message(1)]));
    }

    #[DataProvider('reportingOutcomes')]
    public function testListenerFailureDoesNotThrottleDeliveryAndReporterFailureEscapes(bool $reporterFails): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock(Queue::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $events = new Dispatcher;
        $listenerFailure = new RuntimeException('Listener failed.');
        $reporterFailure = new RuntimeException('Reporter failed.');
        $released = false;
        $queues->expects('connection')->andReturn($queue);
        $queue->expects('pushRaw')->andReturn('accepted');
        $repository->expects('settle')->andReturn(1);
        $events->listen(OutboxBatchDelivered::class, static function () use ($listenerFailure, &$released): void {
            Coroutine::defer(static function () use (&$released): void { $released = true; });
            throw $listenerFailure;
        });
        $events->listen(OutboxBatchDeliveryFailed::class, fn () => $this->fail('Listener failure must not change the delivery event.'));
        $report = $exceptions->expects('report')->with($listenerFailure, m::type('array'));

        if ($reporterFails) {
            $report->andThrow($reporterFailure);
        }

        try {
            $this->assertTrue((new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', [$this->message(1)]));
            $this->assertFalse($reporterFails, 'Reporter failure must reach the worker.');
        } catch (RuntimeException $exception) {
            $this->assertTrue($reporterFails);
            $this->assertSame($reporterFailure, $exception);
        }

        $this->assertTrue($released);
    }

    /**
     * Exercise observer reporting with a healthy and failed exception handler.
     */
    public static function reportingOutcomes(): array
    {
        return [[false], [true]];
    }

    #[TestWith(['sequential', false])]
    #[TestWith(['sequential', true])]
    #[TestWith(['publication', true])]
    #[TestWith(['settlement', true])]
    #[TestWith(['notification', true])]
    public function testIndependentPhaseCancellationReleasesResourcesAndStopsDelivery(string $phase, bool $cleanupThrows): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $queues = m::mock(Factory::class);
        $queue = m::mock($phase === 'sequential' ? Queue::class : Queue::class . ', ' . PublishesOutboxMessages::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Phase cleanup failed.', previous: $cancellation);
        }
        $released = false;
        $queues->expects('connection')->andReturn($queue);
        $cancel = static function () use ($cancellation, &$released): never {
            Coroutine::defer(static function () use (&$released): void { $released = true; });
            throw $cancellation;
        };
        $events = new Dispatcher;

        if ($phase === 'sequential') {
            $queue->expects('pushRaw')->andReturnUsing($cancel);
        } elseif ($phase === 'publication') {
            $queue->expects('publishOutboxMessages')->andReturnUsing($cancel);
        } else {
            $queue->expects('publishOutboxMessages')->andReturn(new OutboxBatchResult([1]));
            if ($phase === 'settlement') {
                $repository->expects('settle')->andReturnUsing($cancel);
            } else {
                $repository->expects('settle')->andReturn(1);
                $events->listen(OutboxBatchDelivered::class, $cancel);
            }
        }
        $messages = [$this->message(1)];
        if ($phase !== 'notification') {
            $messages[] = $this->message(2, 'other');
        }

        try {
            (new OutboxRelay($repository, $queues, $events, $exceptions))->deliver('primary', $messages);
            $this->fail('Child cancellation must stop delivery.');
        } catch (ChildCancellationException $exception) {
            $this->assertSame($cancellation, $exception->getPrevious());
        }

        $this->assertTrue($released);
    }

    #[DataProvider('driverCancellationOutcomes')]
    public function testDriverCancellationStopsDeliveryWithoutSettlement(string $outcome, bool $child): void
    {
        $repository = m::mock(DatabaseOutboxRepository::class);
        $repository->shouldNotReceive('settle');
        $queues = m::mock(Factory::class);
        $queue = m::mock($outcome === 'sequential' ? Queue::class : Queue::class . ', ' . PublishesOutboxMessages::class);
        $exceptions = m::mock(ExceptionHandler::class);
        $exceptions->shouldNotReceive('report');
        $cancellation = new RuntimeException('Observer cleanup failed.', previous: new CanceledException);
        if ($child) {
            $cancellation = new ChildCancellationException('Publication child canceled.', previous: $cancellation);
        }
        $queues->expects('connection')->with('redis')->andReturn($queue);
        $publication = $queue->expects($outcome === 'sequential' ? 'pushRaw' : 'publishOutboxMessages');
        $outcome === 'returned'
            ? $publication->andReturn(new OutboxBatchResult([1], failure: $cancellation))
            : $publication->andThrow($cancellation);

        try {
            (new OutboxRelay($repository, $queues, new Dispatcher, $exceptions))->deliver('primary', [$this->message(1), $this->message(2, 'other')]);
            $this->fail('Driver cancellation must stop delivery.');
        } catch (ChildCancellationException $exception) {
            $this->assertSame($cancellation, $child ? $exception : $exception->getPrevious());
        }
    }

    /**
     * Cover cancellation thrown by drivers or returned directly and through the sequential fallback.
     */
    public static function driverCancellationOutcomes(): array
    {
        return [
            'returned wrapped cancellation' => ['returned', false],
            'thrown wrapped cancellation' => ['thrown', false],
            'sequential child cancellation' => ['sequential', true],
            'thrown child cancellation' => ['thrown', true],
        ];
    }

    /**
     * Build a message whose age and diagnostics are visible to observers.
     */
    protected function message(int $id, string $connection = 'redis', ?int $deliverAt = null): OutboxMessage
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return new OutboxMessage($id, "job-{$id}", 'lease', 1, $connection, 'orders', '{"displayName":"SendInvoice"}', [], $deliverAt, $now - 20, $now - 30);
    }
}
