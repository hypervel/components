<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class TextEnd extends StreamEvent
{
    /**
     * Create a text end event.
     */
    public function __construct(
        public string $id,
        public string $messageId,
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
            'type' => 'text_end',
            'message_id' => $this->messageId,
            'timestamp' => $this->timestamp,
        ];
    }
}
