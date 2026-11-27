<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

class ToolInputStart extends StreamEvent
{
    /**
     * Create a tool input start event.
     */
    public function __construct(
        public string $id,
        public string $toolId,
        public string $toolName,
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
            'type' => 'tool_input_start',
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
            'timestamp' => $this->timestamp,
        ];
    }
}
