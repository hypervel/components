<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SqsOutboxPublicationTest extends TestCase
{
    #[DataProvider('deliveryTimes')]
    public function testPublicationPreservesQueueDefaultsAndAbsoluteTimers(?int $offset, bool $fifo, ?int $expected): void
    {
        CarbonImmutable::setTestNow('2040-01-01 12:00:00');
        $client = m::mock(SqsClient::class);
        $queue = new SqsQueue($client, 'orders', 'https://sqs.test/account');
        $message = $this->message(1, $fifo ? 'orders.fifo' : 'orders', $offset);
        $client->expects('sendMessageBatch')->andReturnUsing(function (array $arguments) use ($fifo, $expected): Result {
            $entry = $arguments['Entries'][0];
            $this->assertSame('https://sqs.test/account/orders' . ($fifo ? '.fifo' : ''), $arguments['QueueUrl']);

            if ($expected === null) {
                $this->assertArrayNotHasKey('DelaySeconds', $entry);
            } else {
                $this->assertSame($expected, $entry['DelaySeconds']);
            }

            if ($fifo) {
                $this->assertSame('tenant:17', $entry['MessageGroupId']);
                $this->assertSame('captured-deduplication', $entry['MessageDeduplicationId']);
            }

            return new Result(['Successful' => [['Id' => '1', 'MessageId' => 'sqs-1']]]);
        });

        $result = $queue->publishOutboxMessages([$message]);

        $this->assertSame([1], $result->accepted);
        $this->assertNull($result->failure);
    }

    /**
     * Provide absent, due, elapsed, capped and FIFO timers.
     */
    public static function deliveryTimes(): array
    {
        return [[null, false, null], [0, false, 0], [-30, false, 0], [30, false, 30], [1000, false, 900], [0, true, null]];
    }

    #[TestWith(['ordinary'])]
    #[TestWith(['cancellation'])]
    #[TestWith(['cleanup'])]
    public function testBatchSizeLimitsAndLaterSdkFailureRetainEarlierConfirmation(string $failureType): void
    {
        $client = m::mock(SqsClient::class);
        $queue = new SqsQueue($client, 'orders');
        $failure = match ($failureType) {
            'cancellation' => new CanceledException,
            'cleanup' => new RuntimeException('SDK cleanup failed.', previous: new CanceledException),
            default => new RuntimeException('SDK retries exhausted.'),
        };
        $calls = 0;
        $client->expects('sendMessageBatch')->twice()->andReturnUsing(function (array $arguments) use (&$calls, $failure): Result {
            if (++$calls === 2) {
                $this->assertCount(1, $arguments['Entries']);
                throw $failure;
            }

            $this->assertCount(10, $arguments['Entries']);

            return new Result(['Successful' => array_map(static fn (array $entry): array => ['Id' => $entry['Id'], 'MessageId' => 'sqs-' . $entry['Id']], $arguments['Entries'])]);
        });

        try {
            $result = $queue->publishOutboxMessages(array_map(fn (int $id): OutboxMessage => $this->message($id), range(1, 11)));
        } catch (Throwable $exception) {
            $this->assertNotSame('ordinary', $failureType);
            $this->assertSame($failure, $exception);

            return;
        }

        $this->assertSame('ordinary', $failureType, 'Cancellation must propagate instead of returning a batch result.');
        $this->assertSame(range(1, 10), $result->accepted);
        $this->assertSame($failure, $result->failure);
    }

    public function testByteLimitSplitsLargeMessagesBeforeTheCountLimit(): void
    {
        $client = m::mock(SqsClient::class);
        $queue = new SqsQueue($client, 'orders');
        $client->expects('sendMessageBatch')->twice()->andReturnUsing(function (array $arguments): Result {
            $this->assertCount(1, $arguments['Entries']);

            return new Result(['Successful' => [['Id' => $arguments['Entries'][0]['Id'], 'MessageId' => 'accepted']]]);
        });

        $result = $queue->publishOutboxMessages([
            $this->message(1, payload: json_encode(['data' => str_repeat('a', 600000)])),
            $this->message(2, payload: json_encode(['data' => str_repeat('b', 600000)])),
        ]);

        $this->assertSame([1, 2], $result->accepted);
        $this->assertNull($result->failure);
    }

    public function testExplicitRejectionCleansOnlyThatAttemptAndRetainsConfirmedEntries(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $store = new Repository(new WorkerArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with(null)->andReturn($store);
        $container->instance('cache', $cache);
        $client = m::mock(SqsClient::class);
        $queue = new SqsQueue($client, 'orders', overflowStorage: ['enabled' => true, 'always' => true]);
        $queue->setContainer($container);
        $first = $this->message(1);
        $secondAttempt = new OutboxMessage(
            $first->id,
            $first->uuid,
            (string) Str::uuid(),
            2,
            $first->connection,
            $first->queue,
            $first->payload,
            $first->options,
            null,
            1,
            1,
        );
        $accepted = $this->message(2);
        $paths = [];
        $calls = 0;
        $client->expects('sendMessageBatch')->twice()->andReturnUsing(function (array $arguments) use (&$calls, &$paths, $store): Result {
            foreach ($arguments['Entries'] as $entry) {
                $path = json_decode($entry['MessageBody'], true)['@pointer'];
                $paths[] = $path;
                $this->assertTrue($store->has($path));
            }

            if (++$calls === 1) {
                throw new RuntimeException('Acceptance is ambiguous.');
            }

            return new Result([
                'Successful' => [['Id' => '2', 'MessageId' => 'confirmed']],
                'Failed' => [['Id' => '1', 'Code' => 'Rejected', 'Message' => 'Rejected entry', 'SenderFault' => true]],
            ]);
        });

        $this->assertSame([], $queue->publishOutboxMessages([$first])->accepted);
        $result = $queue->publishOutboxMessages([$secondAttempt, $accepted]);

        $this->assertSame([2], $result->accepted);
        $this->assertInstanceOf(SqsException::class, $result->failure);
        $this->assertStringContainsString('rejected [1] of [2]', $result->failure->getMessage());
        $this->assertNotSame($paths[0], $paths[1]);
        $this->assertSame($first->payload, $store->get($paths[0]));
        $this->assertFalse($store->has($paths[1]));
        $this->assertSame($accepted->payload, $store->get($paths[2]));
    }

    /**
     * Build a claimed envelope with previously captured FIFO options.
     */
    protected function message(int $id, string $queue = 'orders', ?int $offset = null, ?string $payload = null): OutboxMessage
    {
        $uuid = (string) Str::uuid();
        $options = str_ends_with($queue, '.fifo')
            ? ['transport' => ['sqs' => ['MessageGroupId' => 'tenant:17', 'MessageDeduplicationId' => 'captured-deduplication']]]
            : [];

        return new OutboxMessage(
            $id,
            $uuid,
            (string) Str::uuid(),
            1,
            'sqs',
            $queue,
            $payload ?? json_encode(['uuid' => $uuid, 'data' => ['command' => 'opaque']]),
            $options,
            $offset === null ? null : CarbonImmutable::now()->getTimestamp() + $offset,
            1,
            1,
        );
    }
}
