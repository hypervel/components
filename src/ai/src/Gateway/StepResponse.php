<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\Concerns\HasRawResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

class StepResponse implements Arrayable, JsonSerializable
{
    use HasRawResponse;

    /**
     * Create a response for one generation step.
     *
     * @param ToolCall[] $toolCalls
     * @param null|array<string, mixed> $structured
     * @param array<array-key, mixed> $replayBlocks
     * @param PendingApproval[] $pendingApprovals
     * @param ProviderToolCall[] $providerToolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public TextUsage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $continuationToken = null,
        public array $replayBlocks = [],
        public array $pendingApprovals = [],
        public string $reasoning = '',
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
            'structured' => $this->structured,
            'tool_calls' => array_map(fn (ToolCall $toolCall): array => $toolCall->toArray(), $this->toolCalls),
            'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $this->providerToolCalls),
            'replay_blocks' => $this->replayBlocks,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'continuation_token' => $this->continuationToken,
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
