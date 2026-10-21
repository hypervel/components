<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class Error extends StreamEvent
{
    /**
     * Create a stream error event.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $message,
        public bool $recoverable,
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
            'type' => $this->type,
            'message' => $this->message,
            'recoverable' => $this->recoverable,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }
}
