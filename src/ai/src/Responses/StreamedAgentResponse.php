<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Support\Collection;

class StreamedAgentResponse extends AgentResponse
{
    use JoinsReasoning;

    /** @var Collection<int, StreamEvent> */
    public Collection $events;

    /**
     * Build an agent response from its recorded stream events.
     *
     * @param Collection<int, StreamEvent> $events
     */
    public function __construct(string $invocationId, Collection $events, Meta $meta)
    {
        $textParts = [];
        $stepText = '';
        $reasoningBlocks = [];
        $usage = new TextUsage;
        $toolCalls = [];
        $toolResults = [];
        $citations = [];
        $pendingApprovals = [];
        $steps = null;

        foreach ($events as $event) {
            if ($event instanceof StreamStart) {
                // Content-block message IDs may change mid-sentence; only a new step separates text.
                if (trim($stepText) !== '') {
                    $textParts[] = $stepText;
                }

                $stepText = '';
            } elseif ($event instanceof TextDelta) {
                $stepText .= $event->delta;
            } elseif ($event instanceof ReasoningDelta) {
                $reasoningBlocks[$event->reasoningId] ??= '';
                $reasoningBlocks[$event->reasoningId] .= $event->delta;
            } elseif ($event instanceof StreamEnd) {
                $usage = $usage->add($event->usage);
                $steps = $event->steps;
            } elseif ($event instanceof ToolCall) {
                $toolCalls[] = $event->toolCall;
            } elseif ($event instanceof ToolResult) {
                if (! $event->preliminary) {
                    $toolResults[] = $event->toolResult;
                }
            } elseif ($event instanceof Citation) {
                $citations[] = $event->citation;
            } elseif ($event instanceof ToolApprovalRequest) {
                foreach ($event->pendingApprovals as $approval) {
                    $pendingApprovals[] = $approval;
                }

                $steps = $event->steps;
            }
        }

        if (trim($stepText) !== '') {
            $textParts[] = $stepText;
        }

        parent::__construct($invocationId, implode("\n\n", $textParts), $usage, $meta);

        $this->withToolCallsAndResults(
            toolCalls: new Collection($toolCalls),
            toolResults: new Collection($toolResults),
        );

        $this->events = $events;

        $this->reasoning = static::joinReasoning(array_values($reasoningBlocks));

        // A streamed run only ever sees its citations as events, not on the parsed body...
        $this->meta->citations = new Collection($citations);

        $this->withPendingApprovals(new Collection($pendingApprovals));

        $this->withSteps($steps ?? new Collection);
    }
}
