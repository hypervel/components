<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Queueable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

        $this->expectException(\TypeError::class);
        $job->onConnection(IntConnectionEnum::Redis);
    }

    public function testAllOnConnectionWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(\TypeError::class);
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

        $this->expectException(\TypeError::class);
        $job->onQueue(IntQueueEnum::High);
    }

    public function testAllOnQueueWithIntBackedEnumThrowsTypeError(): void
    {
        $job = new FakeJob();

        $this->expectException(\TypeError::class);
        $job->allOnQueue(IntQueueEnum::High);
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
