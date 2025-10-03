<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Exception;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\JobPayload;
use Hypervel\Horizon\Jobs\MonitorTag;
use Hypervel\Horizon\Jobs\RetryFailedJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class RetryJobTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        unset($_SERVER['horizon.fail']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['horizon.fail']);
    }

    public function testNothingHappensForFailedJobThatDoesntExist()
    {
        dispatch(new RetryFailedJob('12345'));
    }

    public function testFailedJobCanBeRetriedSuccessfullyWithAFreshId()
    {
        $_SERVER['horizon.fail'] = true;
        $id = Queue::push(new Jobs\ConditionallyFailingJob());
        $this->work();
        $this->assertSame(1, $this->failedJobs());

        // Monitor the tag so the job is stored in the completed table...
        dispatch(new MonitorTag('first'));

        unset($_SERVER['horizon.fail']);
        dispatch(new RetryFailedJob($id));

        // Test status is set to pending...
        $retried = Redis::connection('horizon')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);
        $this->assertSame('pending', $retried[0]['status']);

        // Work the now-passing job...
        $this->work();

        $this->assertSame(1, $this->failedJobs());
        $this->assertSame(1, $this->monitoredJobs('first'));

        // Test that retry job ID reference is stored on original failed job...
        $retried = Redis::connection('horizon')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);
        $this->assertCount(1, $retried);
        $this->assertNotNull($retried[0]['id']);
        $this->assertNotNull($retried[0]['retried_at']);

        // Test status is now completed on the retry...
        $this->assertSame('completed', $retried[0]['status']);
    }

    public function testStatusIsUpdatedForDoubleFailingJobs()
    {
        $_SERVER['horizon.fail'] = true;
        $id = Queue::push(new Jobs\ConditionallyFailingJob());
        $this->work();
        dispatch(new RetryFailedJob($id));
        $this->work();

        // Test that retry job ID reference is stored on original failed job...
        $retried = Redis::connection('horizon')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);

        // Test status is now failed on the retry...
        $this->assertSame('failed', $retried[0]['status']);
    }

    public function testRetryingFailedJobWithRetryUntilAndWithoutPushedAt()
    {
        $repository = $this->app->make(JobRepository::class);

        $job = new Jobs\FailingJob();
        $payload = new JobPayload(
            json_encode([
                'id' => '1',
                'displayName' => 'foo',
                'retryUntil' => now()->addMinute(3)->timestamp,
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'data' => [
                    'commandName' => Jobs\ConditionallyFailingJob::class,
                    'command' => serialize($job),
                ],
            ])
        );

        $repository->failed(new Exception('Failed Job'), 'redis', 'default', $payload);

        dispatch(new RetryFailedJob('1'));
        $this->work();

        $retried = Redis::connection('horizon')->hGet('1', 'retried_by');
        $retried = json_decode($retried, true);

        $this->assertSame('pending', $retried[0]['status']);
    }
}
