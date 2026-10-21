<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Override;

class StructuredStep extends Step
{
    /**
     * Create a structured generation step.
     *
     * @param array<string, mixed> $structured
     * @param array<int, ToolCall> $toolCalls
     * @param array<int, ToolResult> $toolResults
     * @param array<int, array<string, mixed>> $replayBlocks
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        TextUsage $usage,
        Meta $meta,
        string $reasoning,
        array $replayBlocks,
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta, $reasoning, $replayBlocks);
    }

    /**
     * Get the instance as an array.
     */
    #[Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'structured' => $this->structured];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
