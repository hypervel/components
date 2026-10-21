<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Contracts;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Webhooks\WebhookPayload;

interface WebhookSender
{
    /**
     * Send a prepared webhook payload for the application.
     *
     * Immediate webhooks are sent from the Reverb worker handling the event, and
     * batches from the flush job, so implementations should hand the payload off
     * promptly instead of performing the HTTP request themselves. Return only once
     * the payload has been accepted, such as queued or stored, and throw when that
     * fails: a returning call releases the batch's claim, while a failure leaves
     * the batch to be sent again.
     *
     * @param array<string, mixed> $config the application's webhook configuration
     */
    public function send(Application $application, array $config, WebhookPayload $payload): void;
}
