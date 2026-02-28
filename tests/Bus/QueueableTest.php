<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Queueable;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * @internal
 * @coversNothing
 */
class QueueableTest extends TestCase
{
    #[DataProvider('connectionDataProvider')]
    public function testOnConnection(mixed $connection, ?string $expected): void
    {
        $job = new FakeJob();
        $job->onConnection($connection);

        $this->assertSame($job->connection, $expected);
    }

    #[DataProvider('connectionDataProvider')]
    public function testAllOnConnection(mixed $connection, ?string $expected): void
    {
        $job = new FakeJob();
        $job->allOnConnection($connection);

        $this->assertSame($job->connection, $expected);
        $this->assertSame($job->chainConnection, $expected);
    }

    public static function connectionDataProvider(): array
    {
        return [
            'uses string' => ['redis', 'redis'],
            'uses string-backed enum' => [ConnectionEnum::SQS, 'sqs'],
            'uses unit enum' => [UnitConnectionEnum::Sync, 'Sync'],
            'uses null' => [null, null],
        ];
    }

    public function testOnConnectionWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(TypeError::class);
        $job->onConnection(IntConnectionEnum::Redis);
    }

    public function testAllOnConnectionWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(TypeError::class);
        $job->allOnConnection(IntConnectionEnum::Redis);
    }

    #[DataProvider('queuesDataProvider')]
    public function testOnQueue(mixed $queue, ?string $expected): void
    {
        $job = new FakeJob();
        $job->onQueue($queue);

        $this->assertSame($job->queue, $expected);
    }

    #[DataProvider('queuesDataProvider')]
    public function testAllOnQueue(mixed $queue, ?string $expected): void
    {
        $job = new FakeJob();
        $job->allOnQueue($queue);

        $this->assertSame($job->queue, $expected);
        $this->assertSame($job->chainQueue, $expected);
    }

    public static function queuesDataProvider(): array
    {
        return [
            'uses string' => ['high', 'high'],
            'uses string-backed enum' => [QueueEnum::HIGH, 'high'],
            'uses unit enum' => [UnitQueueEnum::Low, 'Low'],
            'uses null' => [null, null],
        ];
    }

    public function testOnQueueWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(TypeError::class);
        $job->onQueue(IntQueueEnum::High);
    }

    public function testAllOnQueueWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(TypeError::class);
        $job->allOnQueue(IntQueueEnum::High);
    }

    #[DataProvider('groupDataProvider')]
    public function testOnGroup(mixed $group, string $expected)
    {
        $job = new FakeJob();
        $job->onGroup($group);

        $this->assertSame($expected, $job->messageGroup);
    }

    public static function groupDataProvider(): array
    {
        return [
            'uses string' => ['group-1', 'group-1'],
            'uses string-backed enum' => [GroupEnum::Alpha, 'alpha'],
            'uses unit enum' => [UnitGroupEnum::Beta, 'Beta'],
        ];
    }

    public function testWithDeduplicatorClosure()
    {
        $job = new FakeJob();
        $job->withDeduplicator(fn () => 'dedup-id');

        $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
    }

    public function testWithDeduplicatorNull()
    {
        $job = new FakeJob();
        $job->withDeduplicator(null);

        $this->assertNull($job->deduplicator);
    }

    public function testWithDeduplicatorRejectsNonClosureCallable()
    {
        $this->expectException(TypeError::class);

        $job = new FakeJob();
        $job->withDeduplicator('strlen');
    }

    public function testPrependToChainWithMultipleJobs()
    {
        $job = new FakeJob();
        $job->chain([new FakeJob()]);

        $job->prependToChain([new FakeJob(), new FakeJob()]);

        $this->assertCount(3, $job->chained);
        // The two prepended jobs should be first, in the order they were given
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[0]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[1]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[2]));
    }

    public function testAppendToChainWithMultipleJobs()
    {
        $job = new FakeJob();
        $job->chain([new FakeJob()]);

        $job->appendToChain([new FakeJob(), new FakeJob()]);

        $this->assertCount(3, $job->chained);
        // The two appended jobs should be at the end
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[0]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[1]));
        $this->assertInstanceOf(FakeJob::class, unserialize($job->chained[2]));
    }
}

class FakeJob
{
    use Queueable;
}

enum ConnectionEnum: string
{
    case SQS = 'sqs';
    case REDIS = 'redis';
}

enum IntConnectionEnum: int
{
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
    case HIGH = 'high';
    case DEFAULT = 'default';
}

enum IntQueueEnum: int
{
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

enum UnitGroupEnum
{
    case Alpha;
    case Beta;
}
