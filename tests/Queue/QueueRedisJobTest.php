<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Container\Container;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class QueueRedisJobTest extends TestCase
{
    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(stdClass::class));
        $handler->shouldReceive('fire')->once()->with($job, ['data']);

        $job->fire();
    }

    public function testDeleteRemovesTheJobFromRedis(): void
    {
        $job = $this->getJob();
        $job->getRedisQueue()->shouldReceive('deleteReserved')->once()
            ->with('default', $job);

        $job->delete();
    }

    public function testReleaseProperlyReleasesJobOntoRedis(): void
    {
        $job = $this->getJob();
        $job->getRedisQueue()->shouldReceive('deleteAndRelease')->once()
            ->with('default', $job, 1);

        $job->release(1);
    }

    public function testAttemptsUseTheCountReturnedByRedis(): void
    {
        $this->assertSame(2, $this->getJob()->attempts());
    }

    public function testMissingAttemptCountRejectsAnOtherwiseValidPayload(): void
    {
        $payload = json_encode([
            'id' => 'job-id',
            'job' => 'foo',
            'data' => ['data'],
        ], JSON_THROW_ON_ERROR);

        $job = $this->getJob($payload, null);

        $this->assertSame(1, $job->attempts());
        $this->assertSame('job-id', $job->getJobId());

        try {
            $job->payload();
            $this->fail('Expected the payload to be rejected.');
        } catch (InvalidPayloadException $e) {
            $this->assertSame('The Redis queue job payload does not contain a valid attempts count.', $e->getMessage());
            $this->assertSame($payload, $e->value);
        }
    }

    public function testMalformedPayloadHasNoJobIdentifier(): void
    {
        $job = $this->getJob('{invalid', null);

        $this->assertNull($job->getJobId());

        $first = $this->capturePayloadException($job);
        $second = $this->capturePayloadException($job);

        $this->assertSame($first, $second);
        $this->assertSame('{invalid', $first->value);
    }

    protected function capturePayloadException(RedisJob $job): InvalidPayloadException
    {
        try {
            $job->payload();
        } catch (InvalidPayloadException $e) {
            return $e;
        }

        $this->fail('Expected the payload to be rejected.');
    }

    /**
     * Create a Redis job fixture.
     */
    protected function getJob(?string $payload = null, ?int $attempts = 2): RedisJob
    {
        return new RedisJob(
            m::mock(Container::class),
            m::mock(RedisQueue::class),
            $payload ?? json_encode(['job' => 'foo', 'data' => ['data'], 'attempts' => 1], JSON_THROW_ON_ERROR),
            json_encode(['job' => 'foo', 'data' => ['data'], 'attempts' => 2], JSON_THROW_ON_ERROR),
            'connection-name',
            'default',
            $attempts,
        );
    }
}
