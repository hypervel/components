<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Str;

class FlushWebhookBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $appId,
        public array $webhookConfig,
    ) {
        $this->connection = 'redis';
        $this->queue = 'reverb-webhook-flush';
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookBatchBuffer $buffer): void
    {
        // Clear the debounce lock at the START so new events arriving
        // during this flush can schedule a new flush job. This is correct:
        // events after the claim will go into the next batch.
        $buffer->clearFlushLock($this->appId);

        $config = $this->webhookConfig;
        $maxEvents = $config['batching']['max_events'];
        $maxBytes = $config['batching']['max_payload_bytes'];

        // Claim events atomically — moves them from buffer to processing hash.
        // If claim returns empty, either the buffer is empty or another flush
        // is in-flight (processing key exists). Either way, nothing to do.
        // If the job crashes after claiming, the processing hash retains the
        // events with a timestamp — recoverStaleProcessingKeys() handles recovery.
        $events = $buffer->claim($this->appId, $maxEvents, $maxBytes);

        if (empty($events)) {
            return;
        }

        $application = app(ApplicationProvider::class)->findById($this->appId);

        $payload = new WebhookPayload(
            webhookId: (string) Str::orderedUuid(),
            timeMs: (int) (microtime(true) * 1000),
            events: $events,
        );

        WebhookDeliveryJob::dispatch(
            $payload,
            $config['url'],
            $application->key(),
            $application->secret(),
            $config['retries'],
            $config['retry_delay'],
            $config['timeout'],
            $config['headers'],
        );

        // Acknowledge — delete the processing key now that delivery is queued
        $buffer->acknowledge($this->appId);

        // If more events remain in the buffer, schedule another flush immediately
        if ($buffer->hasRemaining($this->appId)) {
            FlushWebhookBatchJob::dispatch($this->appId, $this->webhookConfig)
                ->onQueue('reverb-webhook-flush');
        }
    }
}
