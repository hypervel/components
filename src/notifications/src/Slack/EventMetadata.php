<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack;

use Hyperf\Contract\Arrayable;

class EventMetadata implements Arrayable
{
    /**
     * Create a new event metadata instance.
     */
    public function __construct(
        protected string $type,
        protected array $payload = []
    ) {
    }

    /**
     * Fluently create a new event metadata instance.
     */
    public static function make(string $type, array $payload = []): static
    {
        return new static($type, $payload);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->type,
            'event_payload' => $this->payload,
        ];
    }
}
