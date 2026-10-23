<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Container\Container;
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
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class RedisOutboxPublicationTest extends TestCase
{
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
        )->andReturn(2);

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

    public function testLaterDestinationFailurePreservesEarlierConfirmation(): void
    {
        [$queue, $connection] = $this->queue();
        $failure = new RuntimeException('Redis connection lost.');
        $connection->expects('evalWithShaCache')->withArgs(fn (string $script, array $keys): bool => $keys[0] === 'queues:first')->andReturn(1);
        $connection->expects('evalWithShaCache')->withArgs(fn (string $script, array $keys): bool => $keys[0] === 'queues:second')->andThrow($failure);

        $result = $queue->publishOutboxMessages([$this->message(1, 'first'), $this->message(2, 'second'), $this->message(3, 'third')]);

        $this->assertSame([1], $result->accepted);
        $this->assertSame($failure, $result->failure);
    }

    public function testIncompleteConfirmationRetainsTheEntireDestination(): void
    {
        [$queue, $connection] = $this->queue();
        $connection->expects('evalWithShaCache')->andReturn(1);

        $result = $queue->publishOutboxMessages([$this->message(1), $this->message(2)]);

        $this->assertSame([], $result->accepted);
        $this->assertInstanceOf(RuntimeException::class, $result->failure);
    }

    public function testHorizonStampsPublicationAndDrainsAcceptedNotificationsBeforeStopping(): void
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
        $connection->expects('evalWithShaCache')->andReturnUsing(function (string $script, array $keys, array $arguments) use (&$stored): int {
            $stored = [$arguments[1], $arguments[3]];

            return 2;
        });

        $result = $queue->publishOutboxMessages($messages);

        $this->assertSame([1, 2], $result->accepted);
        $this->assertSame($failure, $result->failure);
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
        $connection = m::mock(RedisProxy::class);
        $connection->shouldAllowMockingMethod('evalWithShaCache');
        $connection->expects('isCluster')->andReturn($cluster);
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
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
