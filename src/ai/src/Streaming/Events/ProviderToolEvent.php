<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class ProviderToolEvent extends StreamEvent
{
    /**
     * Create a provider tool event.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $itemId,
        public string $type,
        public array $data,
        public string $status,
        public int $timestamp,
        public string $provider,
    ) {
    }

    /**
     * Get the event as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'item_id' => $this->itemId,
            'type' => $this->type,
            'data' => $this->data,
            'status' => $this->status,
            'timestamp' => $this->timestamp,
            'provider' => $this->provider,
        ];
    }
}
