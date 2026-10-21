<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Responses\Concerns\HasRawResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Support\Collection;
use Stringable;

class TextResponse implements Stringable
{
    use HasRawResponse;

    /** @var Collection<int, Message> */
    public Collection $messages;

    /** @var Collection<int, ToolCall> */
    public Collection $toolCalls;

    /** @var Collection<int, ToolResult> */
    public Collection $toolResults;

    /** @var Collection<int, Step> */
    public Collection $steps;

    /** @var Collection<int, PendingApproval> */
    public Collection $pendingApprovals;

    public string $reasoning = '';

    /**
     * Create a new text response instance.
     */
    public function __construct(public string $text, public TextUsage $usage, public Meta $meta)
    {
        $this->messages = new Collection;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
        $this->steps = new Collection;
        $this->pendingApprovals = new Collection;
    }

    /**
     * Provide the message context for the response.
     *
     * @param Collection<int, Message> $messages
     */
    public function withMessages(Collection $messages): static
    {
        $this->messages = $messages;

        /** @var Collection<int, ToolCall> $toolCalls */
        $toolCalls = $this->messages
            ->whereInstanceOf(AssistantMessage::class)
            ->map(fn (AssistantMessage $message): Collection => $message->toolCalls)
            ->flatten();

        /** @var Collection<int, ToolResult> $toolResults */
        $toolResults = $this->messages
            ->whereInstanceOf(ToolResultMessage::class)
            ->map(fn (ToolResultMessage $message): Collection => $message->toolResults)
            ->flatten();

        $this->withToolCallsAndResults($toolCalls, $toolResults);

        return $this;
    }

    /**
     * Provide the tool calls and results for the message.
     *
     * @param Collection<int, ToolCall> $toolCalls
     * @param Collection<int, ToolResult> $toolResults
     */
    public function withToolCallsAndResults(Collection $toolCalls, Collection $toolResults): static
    {
        // Filter Anthropic tool use for "JSON mode"...
        $this->toolCalls = $toolCalls->reject(
            fn (ToolCall $toolCall): bool => $toolCall->name === 'output_structured_data'
        )->values();

        $this->toolResults = $toolResults->values();

        return $this;
    }

    /**
     * Provide the reasoning emitted across every step of the response.
     */
    public function withReasoning(string $reasoning): static
    {
        $this->reasoning = $reasoning;

        return $this;
    }

    /**
     * Provide the steps taken to generate the response.
     *
     * @param Collection<int, Step> $steps
     */
    public function withSteps(Collection $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    /**
     * Mark the response as waiting for tool approval.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     */
    public function withPendingApprovals(Collection $pendingApprovals): static
    {
        $this->pendingApprovals = $pendingApprovals->values();

        return $this;
    }

    /**
     * Determine whether the response has tool calls pending approval.
     */
    public function hasPendingApprovals(): bool
    {
        return $this->pendingApprovals->isNotEmpty();
    }

    /**
     * Get the string representation of the object.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
