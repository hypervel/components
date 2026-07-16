<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\ReadsQueueAttributesTest;

use Hypervel\Bus\Queueable as BusQueueable;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Attributes\UniqueFor;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class ReadsQueueAttributesTest extends TestCase
{
    use ReadsQueueAttributes;

    public function testTriesAttributeIsRead(): void
    {
        $job = new TriesJob;

        $this->assertSame(3, $this->getAttributeValue($job, Tries::class));
    }

    public function testTimeoutAttributeIsRead(): void
    {
        $job = new TimeoutJob;

        $this->assertSame(60, $this->getAttributeValue($job, Timeout::class));
    }

    public function testMaxExceptionsAttributeIsRead(): void
    {
        $job = new MaxExceptionsJob;

        $this->assertSame(5, $this->getAttributeValue($job, MaxExceptions::class));
    }

    public function testBackoffAttributeIsRead(): void
    {
        $job = new BackoffJob;

        $this->assertSame(30, $this->getAttributeValue($job, Backoff::class));
    }

    public function testBackoffAttributeWithNamedScalarIsRead(): void
    {
        $job = new BackoffNamedScalarJob;

        $this->assertSame(30, $this->getAttributeValue($job, Backoff::class));
    }

    public function testBackoffAttributeWithArrayIsRead(): void
    {
        $job = new BackoffArrayJob;

        $this->assertSame([10, 20, 30], $this->getAttributeValue($job, Backoff::class));
    }

    public function testBackoffAttributeWithNamedArrayIsRead(): void
    {
        $job = new BackoffNamedArrayJob;

        $this->assertSame([10, 20, 30], $this->getAttributeValue($job, Backoff::class));
    }

    public function testBackoffAttributeWithVariadicValuesIsRead(): void
    {
        $job = new BackoffVariadicJob;

        $this->assertSame([10, 20, 30], $this->getAttributeValue($job, Backoff::class));
    }

    public function testConnectionAttributeIsRead(): void
    {
        $job = new ConnectionJob;

        $this->assertSame('redis', $this->getAttributeValue($job, Connection::class));
    }

    public function testQueueAttributeIsRead(): void
    {
        $job = new QueueJob;

        $this->assertSame('high-priority', $this->getAttributeValue($job, Queue::class));
    }

    public function testUniqueForAttributeIsRead(): void
    {
        $job = new UniqueForJob;

        $this->assertSame(300, $this->getAttributeValue($job, UniqueFor::class));
    }

    public function testDeleteWhenMissingModelsAttributeReturnsTrue(): void
    {
        $job = new DeleteWhenMissingModelsJob;

        $this->assertTrue($this->getAttributeValue($job, DeleteWhenMissingModels::class));
    }

    public function testFailOnTimeoutAttributeReturnsTrue(): void
    {
        $job = new FailOnTimeoutJob;

        $this->assertTrue($this->getAttributeValue($job, FailOnTimeout::class));
    }

    public function testAttributeOnParentClassIsRead(): void
    {
        $job = new ChildJob;

        $this->assertSame(3, $this->getAttributeValue($job, Tries::class));
    }

    public function testAttributeOnTraitIsRead(): void
    {
        $job = new TraitAttributeJob;

        $this->assertSame(45, $this->getAttributeValue($job, Backoff::class));
    }

    public function testPropertyFallbackWhenNoAttribute(): void
    {
        $job = new PropertyOnlyJob;

        $this->assertSame(5, $this->getAttributeValue($job, Tries::class, 'tries'));
    }

    public function testDefaultReturnedWhenNoAttributeOrProperty(): void
    {
        $job = new PlainJob;

        $this->assertNull($this->getAttributeValue($job, Tries::class));
        $this->assertNull($this->getAttributeValue($job, Tries::class, 'tries'));
        $this->assertSame(42, $this->getAttributeValue($job, Tries::class, 'tries', 42));
    }

    public function testDefaultReturnedWhenNoPropertyNameGiven(): void
    {
        $job = new PlainJob;

        $this->assertSame('default', $this->getAttributeValue($job, Tries::class, null, 'default'));
    }

    public function testDefaultPropertyDoesNotOverrideAttribute(): void
    {
        $job = new AttributeAndPropertyJob;

        $this->assertSame(10, $this->getAttributeValue($job, Tries::class, 'tries'));
    }

    public function testChangedPropertyOverridesAttribute(): void
    {
        $job = (new ChangedQueuePropertyJob)->onQueue('low');

        $this->assertSame('low', $this->getAttributeValue($job, Queue::class, 'queue'));
    }

    public function testCachedMetadataStillReadsRuntimePropertyValues(): void
    {
        $first = new ChangedQueuePropertyJob;

        $this->assertSame('high', $this->getAttributeValue($first, Queue::class, 'queue'));

        $second = (new ChangedQueuePropertyJob)->onQueue('critical');

        $this->assertSame('critical', $this->getAttributeValue($second, Queue::class, 'queue'));
    }

    public function testCachedMetadataKeepsCoroutineRuntimePropertiesIsolated(): void
    {
        [$first, $second] = parallel([
            function () {
                $job = (new ChangedQueuePropertyJob)->onQueue('first');

                usleep(5000);

                return $this->getAttributeValue($job, Queue::class, 'queue');
            },
            function () {
                $job = (new ChangedQueuePropertyJob)->onQueue('second');

                usleep(5000);

                return $this->getAttributeValue($job, Queue::class, 'queue');
            },
        ]);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
    }

    public function testAttributeInstanceIsReadFromCachedMetadata(): void
    {
        $job = new DebouncedJob;

        $first = $this->getAttributeInstance($job, DebounceFor::class);
        $second = $this->getAttributeInstance($job, DebounceFor::class);

        $this->assertSame($first, $second);
        $this->assertSame(10, $first?->debounceFor);
        $this->assertSame(60, $first?->maxWait);
    }

    public function testChildPropertyOverridesInheritedAttribute(): void
    {
        $job = new ChildPropertyOverrideJob;

        $this->assertSame(9, $this->getAttributeValue($job, Tries::class, 'tries'));
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

#[Backoff(backoff: 30)]
class BackoffNamedScalarJob
{
}

#[Backoff([10, 20, 30])]
class BackoffArrayJob
{
}

#[Backoff(backoff: [10, 20, 30])]
class BackoffNamedArrayJob
{
}

#[Backoff(10, 20, 30)]
class BackoffVariadicJob
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

#[Backoff(45)]
trait BackoffTrait
{
}

class TraitAttributeJob
{
    use BackoffTrait;
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

#[Queue('high')]
class ChangedQueuePropertyJob
{
    use BusQueueable;
}

#[DebounceFor(10, maxWait: 60)]
class DebouncedJob
{
}

#[Tries(3)]
class ParentPropertyOverrideJob
{
}

class ChildPropertyOverrideJob extends ParentPropertyOverrideJob
{
    public int $tries = 9;
}
