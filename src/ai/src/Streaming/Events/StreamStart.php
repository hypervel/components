<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class StreamStart extends StreamEvent
{
    /**
     * Create a stream start event.
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public int $timestamp,
        public ?array $metadata = null,
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
            'type' => 'stream_start',
            'provider' => $this->provider,
            'model' => $this->model,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }
}
