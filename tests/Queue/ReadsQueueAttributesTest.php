<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\ReadsQueueAttributesTest;

use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Attributes\UniqueFor;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ReadsQueueAttributesTest extends TestCase
{
    use ReadsQueueAttributes;

    public function testTriesAttributeIsRead()
    {
        $job = new TriesJob();

        $this->assertSame(3, $this->getAttributeValue($job, Tries::class));
    }

    public function testTimeoutAttributeIsRead()
    {
        $job = new TimeoutJob();

        $this->assertSame(60, $this->getAttributeValue($job, Timeout::class));
    }

    public function testMaxExceptionsAttributeIsRead()
    {
        $job = new MaxExceptionsJob();

        $this->assertSame(5, $this->getAttributeValue($job, MaxExceptions::class));
    }

    public function testBackoffAttributeIsRead()
    {
        $job = new BackoffJob();

        $this->assertSame(30, $this->getAttributeValue($job, Backoff::class));
    }

    public function testBackoffAttributeWithArrayIsRead()
    {
        $job = new BackoffArrayJob();

        $this->assertSame([10, 20, 30], $this->getAttributeValue($job, Backoff::class));
    }

    public function testConnectionAttributeIsRead()
    {
        $job = new ConnectionJob();

        $this->assertSame('redis', $this->getAttributeValue($job, Connection::class));
    }

    public function testQueueAttributeIsRead()
    {
        $job = new QueueJob();

        $this->assertSame('high-priority', $this->getAttributeValue($job, Queue::class));
    }

    public function testUniqueForAttributeIsRead()
    {
        $job = new UniqueForJob();

        $this->assertSame(300, $this->getAttributeValue($job, UniqueFor::class));
    }

    public function testDeleteWhenMissingModelsAttributeReturnsTrue()
    {
        $job = new DeleteWhenMissingModelsJob();

        $this->assertTrue($this->getAttributeValue($job, DeleteWhenMissingModels::class));
    }

    public function testFailOnTimeoutAttributeReturnsTrue()
    {
        $job = new FailOnTimeoutJob();

        $this->assertTrue($this->getAttributeValue($job, FailOnTimeout::class));
    }

    public function testAttributeOnParentClassIsRead()
    {
        $job = new ChildJob();

        $this->assertSame(3, $this->getAttributeValue($job, Tries::class));
    }

    public function testPropertyFallbackWhenNoAttribute()
    {
        $job = new PropertyOnlyJob();

        $this->assertSame(5, $this->getAttributeValue($job, Tries::class, 'tries'));
    }

    public function testDefaultReturnedWhenNoAttributeOrProperty()
    {
        $job = new PlainJob();

        $this->assertNull($this->getAttributeValue($job, Tries::class));
        $this->assertNull($this->getAttributeValue($job, Tries::class, 'tries'));
        $this->assertSame(42, $this->getAttributeValue($job, Tries::class, 'tries', 42));
    }

    public function testDefaultReturnedWhenNoPropertyNameGiven()
    {
        $job = new PlainJob();

        $this->assertSame('default', $this->getAttributeValue($job, Tries::class, null, 'default'));
    }

    public function testAttributeTakesPrecedenceOverProperty()
    {
        $job = new AttributeAndPropertyJob();

        $this->assertSame(10, $this->getAttributeValue($job, Tries::class, 'tries'));
    }
}

#[Tries(3)]
class TriesJob
{
}

#[Timeout(60)]
class TimeoutJob
{
}

#[MaxExceptions(5)]
class MaxExceptionsJob
{
}

#[Backoff(30)]
class BackoffJob
{
}

#[Backoff([10, 20, 30])]
class BackoffArrayJob
{
}

#[Connection('redis')]
class ConnectionJob
{
}

#[Queue('high-priority')]
class QueueJob
{
}

#[UniqueFor(300)]
class UniqueForJob
{
}

#[DeleteWhenMissingModels]
class DeleteWhenMissingModelsJob
{
}

#[FailOnTimeout]
class FailOnTimeoutJob
{
}

#[Tries(3)]
class ParentJob
{
}

class ChildJob extends ParentJob
{
}

class PropertyOnlyJob
{
    public int $tries = 5;
}

class PlainJob
{
}

#[Tries(10)]
class AttributeAndPropertyJob
{
    public int $tries = 5;
}
