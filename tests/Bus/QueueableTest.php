<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Tests\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class QueueableTest extends TestCase
{
    #[DataProvider('connectionDataProvider')]
    public function testOnConnection(mixed $connection, ?string $expected): void
    {
        $job = new FakeJob;
        $job->onConnection($connection);

        $this->assertSame($job->connection, $expected);
    }

    #[DataProvider('connectionDataProvider')]
    public function testAllOnConnection(mixed $connection, ?string $expected): void
    {
        $job = new FakeJob;
        $job->allOnConnection($connection);

        $this->assertSame($job->connection, $expected);
        $this->assertSame($job->chainConnection, $expected);
    }

    public static function connectionDataProvider(): array
    {
        return [
            'uses string' => ['redis', 'redis'],
            'uses string-backed enum' => [ConnectionEnum::Sqs, 'sqs'],
            'uses integer-backed enum' => [IntConnectionEnum::Redis, '2'],
            'uses zero-backed enum' => [IntConnectionEnum::Zero, '0'],
            'uses unit enum' => [UnitConnectionEnum::Sync, 'Sync'],
            'uses empty string' => ['', ''],
            'uses null' => [null, null],
        ];
    }

    #[DataProvider('queuesDataProvider')]
    public function testOnQueue(mixed $queue, ?string $expected): void
    {
        $job = new FakeJob;
        $job->onQueue($queue);

        $this->assertSame($job->queue, $expected);
    }

    #[DataProvider('queuesDataProvider')]
    public function testAllOnQueue(mixed $queue, ?string $expected): void
    {
        $job = new FakeJob;
        $job->allOnQueue($queue);

        $this->assertSame($job->queue, $expected);
        $this->assertSame($job->chainQueue, $expected);
    }

    public static function queuesDataProvider(): array
    {
        return [
            'uses string' => ['high', 'high'],
            'uses string-backed enum' => [QueueEnum::High, 'high'],
            'uses integer-backed enum' => [IntQueueEnum::High, '2'],
            'uses zero-backed enum' => [IntQueueEnum::Zero, '0'],
            'uses unit enum' => [UnitQueueEnum::Low, 'Low'],
            'uses empty string' => ['', ''],
            'uses null' => [null, null],
        ];
    }

    #[DataProvider('groupDataProvider')]
    public function testOnGroup(mixed $group, int|string $expected): void
    {
        $job = new FakeJob;
        $job->onGroup($group);

        $this->assertSame($expected, $job->messageGroup);
    }

    public static function groupDataProvider(): array
    {
        return [
            'uses string' => ['group-1', 'group-1'],
            'uses string-backed enum' => [GroupEnum::Alpha, 'alpha'],
            'preserves integer-backed enum values' => [IntGroupEnum::One, 1],
            'uses unit enum' => [UnitGroupEnum::Beta, 'Beta'],
        ];
    }

    public function testWithDeduplicatorClosure(): void
    {
        $job = new FakeJob;
        $job->withDeduplicator(fn () => 'dedup-id');

        $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
    }

    public function testWithDeduplicatorNull(): void
    {
        $job = new FakeJob;
        $job->withDeduplicator(null);

        $this->assertNull($job->deduplicator);
    }

    // REMOVED: testWithDeduplicatorRejectsNonClosureCallable - withDeduplicator() now accepts array|callable|null to match Laravel, which includes string callables

    public function testPrependToChainWithMultipleJobs(): void
    {
        $job = new FakeJob;
        $job->chain([new FakeJob]);

        $job->prependToChain([new FakeJob, new FakeJob]);

        $this->assertCount(3, $job->chained);
        // The two prepended jobs should be first, in the order they were given
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[0]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[1]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[2]));
    }

    public function testAppendToChainWithMultipleJobs(): void
    {
        $job = new FakeJob;
        $job->chain([new FakeJob]);

        $job->appendToChain([new FakeJob, new FakeJob]);

        $this->assertCount(3, $job->chained);
        // The two appended jobs should be at the end
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[0]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[1]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[2]));
    }

    public function testDispatchNextJobPreservesExplicitZeroIdentifiers(): void
    {
        $job = new FakeJob;
        $job->chain([(new FakeJob)->onConnection('0')->onQueue('0')]);
        $job->chainConnection = 'default-connection';
        $job->chainQueue = 'default-queue';

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(function (FakeJob $next): bool {
            $this->assertSame('0', $next->connection);
            $this->assertSame('0', $next->queue);

            return true;
        }));
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $job->dispatchNextJobInChain();
    }

    public function testDispatchNextJobInheritsChainIdentifiersForEmptyStrings(): void
    {
        $job = new FakeJob;
        $job->chain([(new FakeJob)->onConnection('')->onQueue('')]);
        $job->chainConnection = 'default-connection';
        $job->chainQueue = 'default-queue';

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with(m::on(function (FakeJob $next): bool {
            $this->assertSame('default-connection', $next->connection);
            $this->assertSame('default-queue', $next->queue);

            return true;
        }));
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $job->dispatchNextJobInChain();
    }
}

class FakeJob
{
    use Queueable;
}

enum ConnectionEnum: string
{
    case Sqs = 'sqs';
    case Redis = 'redis';
}

enum IntConnectionEnum: int
{
    case Zero = 0;
    case Sqs = 1;
    case Redis = 2;
}

enum UnitConnectionEnum
{
    case Sync;
    case Database;
}

enum QueueEnum: string
{
    case High = 'high';
    case Default = 'default';
}

enum IntQueueEnum: int
{
    case Zero = 0;
    case Default = 1;
    case High = 2;
}

enum UnitQueueEnum
{
    case Default;
    case Low;
}

enum GroupEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
}

enum IntGroupEnum: int
{
    case One = 1;
}

enum UnitGroupEnum
{
    case Alpha;
    case Beta;
}
