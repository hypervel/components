<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Ai\Responses\Concerns\HasRawResponse;
use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

class Step implements Arrayable, JsonSerializable
{
    use HasRawResponse;

    /**
     * Create a generation step.
     *
     * @param array<int, ToolCall> $toolCalls
     * @param array<int, ToolResult> $toolResults
     * @param array<int, array<string, mixed>> $replayBlocks
     * @param array<int, ProviderToolCall> $providerToolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $toolResults,
        public FinishReason $finishReason,
        public TextUsage $usage,
        public Meta $meta,
        public string $reasoning,
        public array $replayBlocks,
        public array $providerToolCalls = [],
    ) {
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'tool_results' => $this->toolResults,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage,
            'meta' => $this->meta,
            'reasoning' => $this->reasoning,
            'replay_blocks' => $this->replayBlocks,
            'provider_tool_calls' => $this->providerToolCalls,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
