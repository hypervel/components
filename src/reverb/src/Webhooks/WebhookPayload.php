<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

final readonly class WebhookPayload
{
    /**
     * Create a new webhook payload instance.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public function __construct(
        public int $timeMs,
        public array $events,
    ) {
    }

    /**
     * Convert the payload to a Pusher-spec JSON string.
     */
    public function toJson(): string
    {
        return json_encode([
            'time_ms' => $this->timeMs,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR);
    }
}
