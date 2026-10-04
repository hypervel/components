<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Jobs;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Webhooks\Contracts\WebhookSender;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;

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
    public function handle(WebhookBatchBuffer $buffer, WebhookSender $sender): void
    {
        // Clear the debounce lock at the START so new events arriving
        // during this flush can schedule a new flush job. This is correct:
        // events after the claim will go into the next batch.
        $buffer->clearFlushLock($this->appId);

        $config = $this->webhookConfig;
        $maxEvents = $config['batching']['max_events'];
        $maxBytes = $config['batching']['max_payload_bytes'];

        // Claim a batch atomically — moves events from the buffer to the processing
        // hash. Null means the buffer is empty or another flush holds a current
        // claim. If this job dies after claiming, a later flush takes the batch
        // over with the same webhook ID once the claim times out.
        $batch = $buffer->claim($this->appId, $maxEvents, $maxBytes);

        if ($batch === null) {
            return;
        }

        $application = app(ApplicationProvider::class)->findById($this->appId);

        $sender->send($application, $config, $batch['payload']);

        // A flush that took the batch over owns it now, so this only deletes our own claim.
        $buffer->acknowledge($this->appId, $batch['token']);

        // If more events remain in the buffer, schedule another flush immediately
        if ($buffer->hasRemaining($this->appId)) {
            FlushWebhookBatchJob::dispatch($this->appId, $this->webhookConfig)
                ->onQueue('reverb-webhook-flush');
        }
    }
}
