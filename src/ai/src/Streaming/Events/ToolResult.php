<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;

class ToolResult extends StreamEvent
{
    /**
     * Create a tool result event.
     */
    public function __construct(
        public string $id,
        public ToolResultData $toolResult,
        public bool $successful,
        public ?string $error,
        public int $timestamp,
        public bool $denied = false,
        public bool $preliminary = false,
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
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $this->error,
            'denied' => $this->denied,
            ...($this->preliminary ? ['preliminary' => true] : []),
            'timestamp' => $this->timestamp,
        ];
    }
}
