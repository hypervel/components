<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Protocols;

use Generator;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Support\Arr;

/**
 * The Vercel AI SDK data stream protocol.
 *
 * See: https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelDataProtocol extends StreamProtocol
{
    protected ?string $invocationId = null;

    /**
     * Create a Vercel data protocol formatter.
     */
    public function __construct(protected ?string $messageId = null)
    {
    }

    /**
     * Get the protocol parts that represent the given response's events.
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $this->started = false;
        $this->errored = false;
        $this->invocationId = $response->invocationId;

        $toolCalls = [];
        $reason = null;
        $usage = new TextUsage;

        foreach ($response as $event) {
            // Send one stream start event, wrapping each subsequent provider step in step parts...
            if ($event instanceof StreamStart && $this->started) {
                yield ['type' => 'finish-step'];
                yield ['type' => 'start-step'];

                continue;
            }

            if ($event instanceof ToolCall) {
                $toolCalls[$event->toolCall->id] = true;
            }

            if ($event instanceof Error) {
                $this->errored = true;
            }

            // A result without a local call is valid only when continuing the client message that contains the call...
            if ($event instanceof ToolResult
                && ! isset($toolCalls[$event->toolResult->id])
                && $this->messageId === null) {
                continue;
            }

            if ($event instanceof ToolApprovalRequest) {
                foreach ($event->pendingApprovals as $pendingApproval) {
                    yield from $this->yieldPart([
                        'type' => 'tool-approval-request',
                        'toolCallId' => $pendingApproval->id,
                        'approvalId' => $pendingApproval->id,
                        'reason' => $pendingApproval->reason,
                    ]);
                }

                continue;
            }

            // Hold the finish reason and combined usage of every step for the terminal finish part...
            if ($event instanceof StreamEnd) {
                $reason = $event->reason;
                $usage = $usage->add($event->usage);

                continue;
            }

            if (empty($part = $this->mapEvent($event))) {
                continue;
            }

            yield from $this->yieldPart($part);
        }

        if ($this->started && ! $this->errored) {
            yield ['type' => 'finish-step'];

            if ($reason !== null) {
                yield $this->finishPart($reason, $usage);
            }
        }
    }

    /**
     * Get the protocol parts that terminate a stream interrupted by an exception.
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart(['type' => 'error', 'errorText' => 'An error occurred.']);
    }

    /**
     * Get the response headers for the protocol.
     */
    protected function headers(): array
    {
        return [...parent::headers(), 'x-vercel-ai-ui-message-stream' => 'v1'];
    }

    /**
     * Get the raw frame that terminates the stream.
     */
    protected function terminator(): ?string
    {
        return "data: [DONE]\n\n";
    }

    /**
     * Get the given protocol part, preceded by a start part when one has not been sent yet.
     *
     * @param array<string, mixed> $part
     *
     * @phpstan-impure Iteration starts the message and changes the protocol state.
     */
    protected function yieldPart(array $part): Generator
    {
        if ($part['type'] === 'start') {
            $this->started = true;

            $part['messageId'] = $this->messageId ?? $part['messageId'];

            yield $part;
            yield ['type' => 'start-step'];

            return;
        }

        if (! $this->started) {
            yield from $this->yieldPart(['type' => 'start', 'messageId' => $this->invocationId]);
        }

        yield $part;
    }

    /**
     * Get the protocol part that represents the given event.
     *
     * @return null|array<string, mixed>
     */
    protected function mapEvent(StreamEvent $event): ?array
    {
        return match (true) {
            $event instanceof StreamStart => [
                'type' => 'start',
                'messageId' => $event->id,
            ],
            $event instanceof TextStart => [
                'type' => 'text-start',
                'id' => $event->messageId,
            ],
            $event instanceof TextDelta => [
                'type' => 'text-delta',
                'id' => $event->messageId,
                'delta' => $event->delta,
            ],
            $event instanceof TextEnd => [
                'type' => 'text-end',
                'id' => $event->messageId,
            ],
            $event instanceof ReasoningStart => [
                'type' => 'reasoning-start',
                'id' => $event->reasoningId,
            ],
            $event instanceof ReasoningDelta => [
                'type' => 'reasoning-delta',
                'id' => $event->reasoningId,
                'delta' => $event->delta,
            ],
            $event instanceof ReasoningEnd => [
                'type' => 'reasoning-end',
                'id' => $event->reasoningId,
            ],
            $event instanceof ToolCall => [
                'type' => 'tool-input-available',
                'toolCallId' => $event->toolCall->id,
                'toolName' => $event->toolCall->name,
                'input' => array_is_list($event->toolCall->arguments) ? (object) $event->toolCall->arguments : $event->toolCall->arguments,
            ],
            $event instanceof ToolResult => $this->toolResultPart($event),
            $event instanceof Citation => $this->citationPart($event),
            $event instanceof Error => [
                'type' => 'error',
                'errorText' => $event->message,
            ],
            $event instanceof ProviderToolEvent => $this->providerToolPart($event),
            default => null,
        };
    }

    /**
     * Get the protocol part that represents the given tool result event.
     *
     * @return array<string, mixed>
     */
    protected function toolResultPart(ToolResult $event): array
    {
        if ($event->denied) {
            return [
                'type' => 'tool-output-denied',
                'toolCallId' => $event->toolResult->id,
            ];
        }

        if (! $event->successful) {
            return [
                'type' => 'tool-output-error',
                'toolCallId' => $event->toolResult->id,
                'errorText' => $event->error ?? 'The tool call failed.',
            ];
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $event->toolResult->id,
            'output' => $event->toolResult->result,
            ...($event->preliminary ? ['preliminary' => true] : []),
        ];
    }

    /**
     * Get the protocol part that represents the given provider tool event.
     *
     * @return array<string, mixed>
     */
    protected function providerToolPart(ProviderToolEvent $event): array
    {
        return [
            'type' => 'custom',
            'kind' => $event->provider . '.' . $event->type,
            'providerMetadata' => [
                $event->provider => [
                    'itemId' => $event->itemId,
                    'status' => $event->status,
                    'data' => $event->data,
                ],
            ],
        ];
    }

    /**
     * Get the protocol part that represents the given citation event.
     *
     * @return null|array<string, mixed>
     */
    protected function citationPart(Citation $event): ?array
    {
        return match (true) {
            $event->citation instanceof UrlCitation => Arr::whereNotNull([
                'type' => 'source-url',
                'sourceId' => $event->citation->url,
                'url' => $event->citation->url,
                'title' => $event->citation->title,
            ]),
            default => null,
        };
    }

    /**
     * Get the protocol part that finishes the stream.
     *
     * @return array<string, mixed>
     */
    protected function finishPart(string $reason, TextUsage $usage): array
    {
        return [
            'type' => 'finish',
            'finishReason' => match ($reason) {
                'stop' => 'stop',
                'tool_calls' => 'tool-calls',
                'length' => 'length',
                'content_filter' => 'content-filter',
                'error' => 'error',
                default => 'other',
            },
            'messageMetadata' => [
                'usage' => [
                    'inputTokens' => $usage->inputTokens,
                    'outputTokens' => $usage->outputTokens,
                    'totalTokens' => $usage->totalTokens(),
                    'reasoningTokens' => $usage->reasoningTokens,
                    'cachedInputTokens' => $usage->cacheReadInputTokens,
                ],
            ],
        ];
    }
}
