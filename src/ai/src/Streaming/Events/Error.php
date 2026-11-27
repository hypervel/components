<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Responses\Data\TextUsage;

class Error extends StreamEvent
{
    /**
     * Create a stream error event.
     *
     * @param null|TextUsage $usage the usage the provider reported before the stream failed, if any
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $message,
        public bool $recoverable,
        public int $timestamp,
        public ?array $metadata = null,
        public ?TextUsage $usage = null,
    ) {
    }

    /**
     * Get the name the event is broadcast under.
     */
    public function broadcastAs(): string
    {
        return $this->type === 'stream_failed' ? $this->type : 'error';
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
            'usage' => $this->usage?->toArray(),
        ];
    }
}
