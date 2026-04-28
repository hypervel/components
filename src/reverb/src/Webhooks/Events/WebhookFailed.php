<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Events;

use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Throwable;

class WebhookFailed
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public WebhookPayload $payload,
        public string $url,
        public Throwable $exception,
    ) {
    }
}
