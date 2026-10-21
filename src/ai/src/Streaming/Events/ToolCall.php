<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Responses\Data\ToolCall as ToolCallData;

class ToolCall extends StreamEvent
{
    /**
     * Create a tool call event.
     */
    public function __construct(
        public string $id,
        public ToolCallData $toolCall,
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
            'type' => 'tool_call',
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->arguments,
            'reasoning_id' => $this->toolCall->reasoningId,
            'timestamp' => $this->timestamp,
        ];
    }
}
