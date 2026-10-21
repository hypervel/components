<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class ReasoningStart extends StreamEvent
{
    /**
     * Create a reasoning start event.
     */
    public function __construct(
        public string $id,
        public string $reasoningId,
        public int $timestamp,
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
            'type' => 'reasoning_start',
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
        ];
    }
}
