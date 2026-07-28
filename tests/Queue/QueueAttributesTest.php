<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Tests\TestCase;

class QueueAttributesTest extends TestCase
{
    public function testQueueAttributeNormalizesBackedEnumToString(): void
    {
        $attribute = new Queue(QueueAttributeBackedEnum::Default);

        $this->assertSame('default', $attribute->queue);
    }

    public function testQueueAttributeNormalizesUnitEnumToString(): void
    {
        $attribute = new Queue(QueueAttributeUnitEnum::High);

        $this->assertSame('High', $attribute->queue);
    }

    public function testQueueAttributeKeepsStringAsString(): void
    {
        $attribute = new Queue('high');

        $this->assertSame('high', $attribute->queue);
    }

    public function testQueueAttributePreservesIntegerBackedEnumZero(): void
    {
        $attribute = new Queue(QueueAttributeIntBackedEnum::Zero);

        $this->assertSame('0', $attribute->queue);
    }

    public function testConnectionAttributeNormalizesBackedEnumToString(): void
    {
        $attribute = new Connection(ConnectionAttributeBackedEnum::Redis);

        $this->assertSame('redis', $attribute->connection);
    }

    public function testConnectionAttributeNormalizesUnitEnumToString(): void
    {
        $attribute = new Connection(ConnectionAttributeUnitEnum::Redis);

        $this->assertSame('Redis', $attribute->connection);
    }

    public function testConnectionAttributeKeepsStringAsString(): void
    {
        $attribute = new Connection('redis');

        $this->assertSame('redis', $attribute->connection);
    }

    public function testConnectionAttributePreservesIntegerBackedEnumZero(): void
    {
        $attribute = new Connection(ConnectionAttributeIntBackedEnum::Zero);

        $this->assertSame('0', $attribute->connection);
    }
}

enum QueueAttributeBackedEnum: string
{
    case Default = 'default';
    case High = 'high';
}

enum QueueAttributeIntBackedEnum: int
{
    case Zero = 0;
}

enum QueueAttributeUnitEnum
{
    case High;
    case Default;
}

enum ConnectionAttributeBackedEnum: string
{
    case Redis = 'redis';
    case Sqs = 'sqs';
}

enum ConnectionAttributeIntBackedEnum: int
{
    case Zero = 0;
}

enum ConnectionAttributeUnitEnum
{
    case Redis;
    case Sqs;
}
