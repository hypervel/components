<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\MaxAttemptsExceededException;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\TestCase;

class QueueExceptionTest extends TestCase
{
    public function testInvalidPayloadExceptionHasAStringDefaultMessage(): void
    {
        $exception = new InvalidPayloadException(value: ['invalid']);

        $this->assertSame('Unable to decode the queue job payload.', $exception->getMessage());
        $this->assertSame(['invalid'], $exception->value);
    }

    public function testItCanCreateTimeoutExceptionForJob(): void
    {
        $exception = TimeoutExceededException::forJob($job = new MyFakeRedisJob);

        $this->assertSame('App\Jobs\UnderlyingJob has timed out.', $exception->getMessage());
        $this->assertSame($job, $exception->job);
    }

    public function testItCanCreateMaxAttemptsExceptionForJob(): void
    {
        $exception = MaxAttemptsExceededException::forJob($job = new MyFakeRedisJob);

        $this->assertSame('App\Jobs\UnderlyingJob has been attempted too many times.', $exception->getMessage());
        $this->assertSame($job, $exception->job);
    }
}

class MyFakeRedisJob extends RedisJob
{
    public function __construct()
    {
    }

    public function resolveName(): string
    {
        return 'App\Jobs\UnderlyingJob';
    }
}
