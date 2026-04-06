<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\MaxAttemptsExceededException;
use Hypervel\Queue\TimeoutExceededException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class QueueExceptionTest extends TestCase
{
    public function testItCanCreateTimeoutExceptionForJob()
    {
        $e = TimeoutExceededException::forJob($job = new MyFakeRedisJob);

        $this->assertSame('App\Jobs\UnderlyingJob has timed out.', $e->getMessage());
        $this->assertSame($job, $e->job);
    }

    public function testItCanCreateMaxAttemptsExceptionForJob()
    {
        $e = MaxAttemptsExceededException::forJob($job = new MyFakeRedisJob);

        $this->assertSame('App\Jobs\UnderlyingJob has been attempted too many times.', $e->getMessage());
        $this->assertSame($job, $e->job);
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
