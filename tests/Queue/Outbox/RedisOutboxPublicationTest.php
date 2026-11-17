<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Events\Dispatcher;
use Hypervel\Horizon\Events\JobPending;
use Hypervel\Horizon\Events\JobPushed;
use Hypervel\Horizon\RedisQueue as HorizonRedisQueue;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisOutboxPublicationTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('clusterModes')]
    public function testPublicationBatchesResolvedDestinationsWithoutReencodingPayloads(bool $cluster): void
    {
        CarbonImmutable::setTestNow('2040-01-01 12:00:00');
        [$queue, $connection, $events, $routes] = $this->queue(cluster: $cluster);
        $routes->forward(['orders' => 'processing', 'alias' => 'processing', 'processing' => 'archive']);
        $events->listen(JobQueued::class, fn () => $this->fail('Relay must not repeat the producer lifecycle.'));
        $messages = [$this->message(1, 'orders'), $this->message(2, 'alias', CarbonImmutable::now()->getTimestamp() + 30)];
        $key = $cluster ? 'queues:{processing}' : 'queues:processing';
        $connection->expects('evalWithShaCache')->with(
            LuaScripts::bulk(),
            [$key, $key . ':notify', $key . ':delayed'],
            ['i', $messages[0]->payload, $messages[1]->deliverAt, $messages[1]->payload],
        )->returns(2);

        $result = $queue->publishOutboxMessages($messages);

        $this->assertSame([1, 2], $result->accepted);
        $this->assertNull($result->failure);
        $this->assertSame([], $result->deferred);
    }

    /**
     * Provide standalone and Cluster key layouts.
     */
    public static function clusterModes(): array
    {
        return [[false], [true]];
    }

    #[TestWith(['ordinary'])]
    #[TestWith(['cancellation'])]
    #[TestWith(['cleanup'])]
    public function testLaterDestinationFailurePreservesEarlierConfirmation(string $failureType): void
    {
        [$queue, $connection] = $this->queue();
        $failure = match ($failureType) {
            'cancellation' => new CanceledException,
            'cleanup' => new RuntimeException('Redis cleanup failed.', previous: new CanceledException),
            default => new RuntimeException('Redis connection lost.'),
        };
        $connection->expects('evalWithShaCache')->with(Argument::any(), Argument::satisfies(fn (array $keys): bool => $keys[0] === 'queues:first'), Argument::any())->returns(1);
        $connection->expects('evalWithShaCache')->with(Argument::any(), Argument::satisfies(fn (array $keys): bool => $keys[0] === 'queues:second'), Argument::any())->throws($failure);

        try {
            $result = $queue->publishOutboxMessages([$this->message(1, 'first'), $this->message(2, 'second'), $this->message(3, 'third')]);
        } catch (Throwable $exception) {
            $this->assertNotSame('ordinary', $failureType);
            $this->assertSame($failure, $exception);

            return;
        }

        $this->assertSame('ordinary', $failureType, 'Cancellation must propagate instead of returning a batch result.');
        $this->assertSame([1], $result->accepted);
        $this->assertSame($failure, $result->failure);
    }

    public function testIncompleteConfirmationRetainsTheEntireDestination(): void
    {
        [$queue, $connection] = $this->queue();
        $connection->expects('evalWithShaCache')->returns(1);

        $result = $queue->publishOutboxMessages([$this->message(1), $this->message(2)]);

        $this->assertSame([], $result->accepted);
        $this->assertInstanceOf(RuntimeException::class, $result->failure);
    }

    public function testHorizonStampsPublicationAndKeepsPublishingAfterObserverFailures(): void
    {
        CarbonImmutable::setTestNow('2040-01-01 12:00:00');
        [$queue, $connection, $events] = $this->queue(horizon: true);
        $messages = [$this->message(1), $this->message(2), $this->message(3, 'other')];
        $pending = [];
        $pushed = [];
        $failure = new RuntimeException('Monitoring storage unavailable.');
        $events->listen(JobPending::class, function (JobPending $event) use (&$pending): void {
            $pending[] = $event->payload->value;
        });
        $events->listen(JobPushed::class, function (JobPushed $event) use (&$pushed, $failure): void {
            $pushed[] = $event->payload->value;
            throw $failure;
        });
        $events->listen(JobQueued::class, fn () => $this->fail('Horizon relay must not emit JobQueued.'));
        $stored = [];
        $connection->expects('evalWithShaCache')->times(2)->resolves(function (string $script, array $keys, array $arguments) use (&$stored): int {
            for ($index = 1; $index < count($arguments); $index += 2) {
                $stored[] = $arguments[$index];
            }

            return intdiv(count($arguments), 2);
        });

        $result = $queue->publishOutboxMessages($messages);

        $this->assertSame([1, 2, 3], $result->accepted);
        $this->assertSame($failure, $result->failure);
        $this->assertCount(3, $stored);
        $this->assertSame($stored, $pending);
        $this->assertSame($stored, $pushed);

        foreach ($stored as $index => $payload) {
            $decoded = json_decode($payload, true);
            $original = json_decode($messages[$index]->payload, true);
            $this->assertArrayHasKey('pushedAt', $decoded);
            unset($decoded['pushedAt']);
            $this->assertSame($original, $decoded);
        }
    }

    public function testObserverCancellationStopsPublishingLaterDestinations(): void
    {
        [$queue, $connection, $events] = $this->queue(horizon: true);
        $cancellation = new RuntimeException('Observer cleanup failed.', previous: new CanceledException);
        $events->listen(JobPushed::class, static fn () => throw $cancellation);
        $connection->expects('evalWithShaCache')->returns(1);

        try {
            $queue->publishOutboxMessages([$this->message(1), $this->message(2, 'other')]);
            $this->fail('Expected observer cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCancellationDuringObserverFailureReportingPropagates(): void
    {
        [$queue, $connection, $events] = $this->queue(horizon: true);
        $observerFailure = new RuntimeException('Observer failed.');
        $events->listen(JobPushed::class, static fn () => throw $observerFailure);
        $connection->expects('evalWithShaCache')->with(Argument::any(), Argument::satisfies(fn (array $keys): bool => $keys[0] === 'queues:first'), Argument::any())->returns(1);
        $connection->expects('evalWithShaCache')->with(Argument::any(), Argument::satisfies(fn (array $keys): bool => $keys[0] === 'queues:second'), Argument::any())->throws(new RuntimeException('Redis unavailable.'));
        $cancellation = new RuntimeException('Reporting cleanup failed.', previous: new CanceledException);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($observerFailure)->throws($cancellation);
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        try {
            $queue->publishOutboxMessages([$this->message(1, 'first'), $this->message(2, 'second')]);
            $this->fail('Expected reporting cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    /**
     * Create a real driver over an observable transport boundary.
     */
    protected function queue(bool $cluster = false, bool $horizon = false): array
    {
        $container = new Container;
        Container::setInstance($container);
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventDispatcher::class, $events);
        $container->instance('queue.routes', $routes = new QueueRoutes);
        $connection = Double::for(RedisConnection::class)->strict();
        $connection->allows('getConnection')->returns($connection);
        $connection->allows('shouldTransform');
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->allows('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $manager = Double::for(new PoolManager($container))->passthru();
        $manager->allows('pool')->with('outbox')->returns($pool);
        $proxy = Double::for(new RedisProxy($manager, 'outbox', new RedisSentinelFactory))->passthru();
        $proxy->expects('isCluster')->returns($cluster);
        $factory = Double::for(Factory::class)->strict();
        $factory->allows('connection')->returns($proxy);
        $queue = $horizon ? new HorizonRedisQueue($factory, 'orders') : new RedisQueue($factory, 'orders');
        $queue->setContainer($container)->setConnectionName('redis');

        return [$queue, $connection, $events, $routes];
    }

    /**
     * Build a finalized envelope with immutable staging classification.
     */
    protected function message(int $id, string $queue = 'orders', ?int $deliverAt = null): OutboxMessage
    {
        $uuid = (string) Str::uuid();
        $payload = json_encode([
            'uuid' => $uuid, 'id' => $uuid, 'attempts' => 0,
            'type' => 'job', 'tags' => ['tenant:17'], 'silenced' => false,
            'data' => ['command' => 'opaque serialized command'],
        ]);

        return new OutboxMessage($id, $uuid, (string) Str::uuid(), 1, 'redis', $queue, $payload, [], $deliverAt, 1, 1);
    }
}
