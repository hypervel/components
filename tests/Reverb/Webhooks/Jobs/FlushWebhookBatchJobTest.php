<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks\Jobs;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Webhooks\Contracts\WebhookSender;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use RuntimeException;

class FlushWebhookBatchJobTest extends ReverbTestCase
{
    /**
     * Fake webhook delivery and flush jobs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([WebhookDeliveryJob::class, FlushWebhookBatchJob::class]);
    }

    public function testClearsDebounceBeforeClaiming(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('clearFlushLock')->with('123456')->ordered();
        $buffer->expects('claim')->ordered()->returns(null);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));
    }

    public function testDeliversTheClaimedBatchAndAcknowledgesItsClaim(): void
    {
        $batch = $this->claimedBatch();
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($batch);
        $buffer->expects('acknowledge')->with('123456', 'claim-token');
        $buffer->allows('hasRemaining')->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) use ($batch) {
            return $job->payload === $batch['payload'];
        });
    }

    public function testHandsTheClaimedBatchToTheBoundSender(): void
    {
        $batch = $this->claimedBatch();
        $config = $this->defaultWebhookConfig();
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($batch);
        $buffer->expects('acknowledge')->with('123456', 'claim-token');
        $buffer->allows('hasRemaining')->returns(false);
        $sender = Double::for(WebhookSender::class)->strict();
        $sender->expects('send')->with(
            Argument::satisfies(static fn (Application $application): bool => $application->id() === '123456'),
            $config,
            $batch['payload'],
        );

        $job = new FlushWebhookBatchJob('123456', $config);
        $job->handle($buffer, $sender);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testFailedSendKeepsTheClaimForRecovery(): void
    {
        $failure = new RuntimeException('The sender could not accept the batch.');
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($this->claimedBatch());
        $buffer->expects('acknowledge')->never();
        $buffer->expects('hasRemaining')->never();
        $sender = Double::for(WebhookSender::class)->strict();
        $sender->expects('send')->throws($failure);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());

        try {
            $job->handle($buffer, $sender);
            $this->fail('Expected the sender failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testBailsWhenClaimReturnsEmpty(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns(null);
        $buffer->expects('acknowledge')->never();
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testReschedulesWhenItemsRemain(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($this->claimedBatch());
        $buffer->allows('acknowledge');
        $buffer->allows('hasRemaining')->returns(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertPushed(FlushWebhookBatchJob::class);
    }

    public function testDoesNotRescheduleWhenBufferEmpty(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($this->claimedBatch());
        $buffer->allows('acknowledge');
        $buffer->allows('hasRemaining')->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testUsesAppKeyAndSecretForSigning(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($this->claimedBatch());
        $buffer->allows('acknowledge');
        $buffer->allows('hasRemaining')->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->appKey === 'reverb-key'
                && $job->appSecret === 'reverb-secret';
        });
    }

    public function testPassesCustomHeadersToDeliveryJob(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->allows('clearFlushLock');
        $buffer->allows('claim')->returns($this->claimedBatch());
        $buffer->allows('acknowledge');
        $buffer->allows('hasRemaining')->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $config = $this->defaultWebhookConfig();
        $config['headers'] = ['Authorization' => 'Bearer token'];

        $job = new FlushWebhookBatchJob('123456', $config);
        $job->handle($buffer, $this->app->make(WebhookSender::class));

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->headers === ['Authorization' => 'Bearer token'];
        });
    }

    public function testUsesFlushQueueNotDeliveryQueue(): void
    {
        $job = new FlushWebhookBatchJob('123456', $this->defaultWebhookConfig());

        $this->assertSame('reverb-webhook-flush', $job->queue);
    }

    /**
     * Create a batch as returned by the buffer's claim.
     *
     * @return array{token: string, payload: WebhookPayload}
     */
    protected function claimedBatch(): array
    {
        return [
            'token' => 'claim-token',
            'payload' => new WebhookPayload(
                webhookId: 'batch-webhook-id',
                timeMs: 1712000000000,
                events: [
                    ['name' => 'channel_occupied', 'channel' => 'test-channel'],
                    ['name' => 'channel_vacated', 'channel' => 'test-channel'],
                ],
            ),
        ];
    }

    /**
     * Default webhook config for tests.
     */
    protected function defaultWebhookConfig(): array
    {
        $config = $this->webhookConfig();
        $config['url'] = 'https://example.com/webhook';
        $config['events'] = ['channel_occupied', 'channel_vacated'];
        $config['batching']['enabled'] = true;

        return $config;
    }
}
