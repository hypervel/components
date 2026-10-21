<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Webhooks\Contracts\WebhookSender;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;

class QueuedWebhookSender implements WebhookSender
{
    /**
     * Send a prepared webhook payload for the application.
     *
     * @param array<string, mixed> $config
     */
    public function send(Application $application, array $config, WebhookPayload $payload): void
    {
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
    }
}
