<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Cohere\Concerns;

use Generator;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Support\Str;
use Psr\Http\Message\StreamInterface;

trait HandlesTextStreaming
{
    /**
     * Process a Cohere Chat API event stream.
     *
     * @return Generator<int, StreamEvent, mixed, null|StepResponse>
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        StreamInterface $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = null;
        $textStartEmitted = false;
        $currentText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $usage = null;
        $finishReason = null;
        $messageEnded = false;

        yield (new StreamStart(
            $this->generateEventId(),
            $provider->name(),
            $model,
            time(),
        ))->withInvocationId($invocationId);

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $delta = $data['delta'] ?? [];

            switch ($data['type'] ?? null) {
                case 'content-delta':
                    $thinking = (string) ($delta['message']['content']['thinking'] ?? '');
                    $content = (string) ($delta['message']['content']['text'] ?? '');

                    if ($thinking !== '') {
                        if ($reasoningId === null) {
                            $reasoningId = $this->generateEventId();

                            yield (new ReasoningStart(
                                $this->generateEventId(),
                                $reasoningId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $thinking,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    if ($content !== '') {
                        yield from $this->endReasoning($invocationId, $reasoningId);

                        if (! $textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $currentText .= $content;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $content,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    break;
                case 'tool-call-start':
                    yield from $this->endReasoning($invocationId, $reasoningId);

                    $toolCall = $delta['message']['tool_calls'] ?? [];

                    $pendingToolCalls[$data['index']] = [
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'arguments' => (string) ($toolCall['function']['arguments'] ?? ''),
                    ];

                    break;
                case 'tool-call-delta':
                    if (isset($pendingToolCalls[$data['index']])) {
                        $pendingToolCalls[$data['index']]['arguments'] .= (string) ($delta['message']['tool_calls']['function']['arguments'] ?? '');
                    }

                    break;
                case 'message-end':
                    $messageEnded = true;

                    if (filled($delta['error'] ?? null)) {
                        yield (new Error(
                            $this->generateEventId(),
                            strtolower($delta['finish_reason'] ?? 'error'),
                            $delta['error'],
                            false,
                            time(),
                        ))->withInvocationId($invocationId);

                        return null;
                    }

                    $finishReason = $delta['finish_reason'] ?? null;
                    $usage = $this->extractUsage($delta['usage'] ?? []);

                    break;
            }
        }

        // EOF alone does not confirm that text or tool arguments are complete.
        if (! $messageEnded) {
            yield (new Error(
                $this->generateEventId(),
                'incomplete_stream',
                'The provider stream ended before the response was complete.',
                false,
                time(),
            ))->withInvocationId($invocationId);

            return null;
        }

        yield from $this->endReasoning($invocationId, $reasoningId);

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        foreach ($pendingToolCalls as $pending) {
            $toolCall = new ToolCall(
                $pending['id'],
                $pending['name'],
                json_decode($pending['arguments'] ?: '{}', true) ?? [],
                $pending['id'] ?: null,
            );

            $toolCalls[] = $toolCall;

            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($finishReason),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $model),
        );
    }

    /**
     * Emit a reasoning end event if a reasoning block is open.
     *
     * @return Generator<int, StreamEvent>
     */
    protected function endReasoning(string $invocationId, ?string &$reasoningId): Generator
    {
        if ($reasoningId === null) {
            return;
        }

        yield (new ReasoningEnd(
            $this->generateEventId(),
            $reasoningId,
            time(),
        ))->withInvocationId($invocationId);

        $reasoningId = null;
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
