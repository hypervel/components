<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks\Jobs;

use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class FlushWebhookBatchJobTest extends ReverbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([WebhookDeliveryJob::class, FlushWebhookBatchJob::class]);
    }

    public function testClearsDebounceBeforeClaiming()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock')->with('123456')->once()->ordered();
        $buffer->shouldReceive('claim')->once()->ordered()->andReturn([]);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);
    }

    public function testClaimsEventsAndDispatchesDeliveryJob()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test-channel'],
            ['name' => 'channel_vacated', 'channel' => 'test-channel'],
        ]);
        $buffer->shouldReceive('acknowledge')->with('123456')->once();
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return count($job->payload->events) === 2
                && $job->payload->events[0]['name'] === 'channel_occupied'
                && $job->payload->events[1]['name'] === 'channel_vacated';
        });
    }

    public function testSetsWebhookIdAndTimeMsOnPayload()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test'],
        ]);
        $buffer->shouldReceive('acknowledge');
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->webhookId !== ''
                && strlen($job->payload->webhookId) === 36
                && $job->payload->timeMs > 0;
        });
    }

    public function testBailsWhenClaimReturnsEmpty()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([]);
        $buffer->shouldNotReceive('acknowledge');
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testReschedulesWhenItemsRemain()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test'],
        ]);
        $buffer->shouldReceive('acknowledge');
        $buffer->shouldReceive('hasRemaining')->andReturn(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertPushed(FlushWebhookBatchJob::class);
    }

    public function testDoesNotRescheduleWhenBufferEmpty()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test'],
        ]);
        $buffer->shouldReceive('acknowledge');
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testUsesAppKeyAndSecretForSigning()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test'],
        ]);
        $buffer->shouldReceive('acknowledge');
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->appKey === 'reverb-key'
                && $job->appSecret === 'reverb-secret';
        });
    }

    public function testPassesCustomHeadersToDeliveryJob()
    {
        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock');
        $buffer->shouldReceive('claim')->andReturn([
            ['name' => 'channel_occupied', 'channel' => 'test'],
        ]);
        $buffer->shouldReceive('acknowledge');
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $config = $this->defaultWebhookConfig();
        $config['headers'] = ['Authorization' => 'Bearer token'];

        $job = new FlushWebhookBatchJob('123456', $config);
        $job->handle($buffer);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->headers === ['Authorization' => 'Bearer token'];
        });
    }

    public function testUsesFlushQueueNotDeliveryQueue()
    {
        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());

        $this->assertSame('reverb-webhook-flush', $job->queue);
    }

    /**
     * Default webhook config for tests.
     */
    protected function defaultWebhookConfig(): array
    {
        return [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied', 'channel_vacated'],
            'headers' => [],
            'batching' => [
                'enabled' => true,
                'max_events' => 50,
                'max_payload_bytes' => 262144,
            ],
            'retries' => 3,
            'retry_delay' => 1,
            'timeout' => 5,
        ];
    }
}
