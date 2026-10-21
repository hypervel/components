<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter\Concerns;

use Generator;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
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
     * Process a streaming response for a single text generation step.
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
        $reasoningDetails = [];
        $streamModel = $model;
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            if (isset($data['error'])) {
                yield (new Error(
                    $this->generateEventId(),
                    (string) ($data['error']['code'] ?? 'unknown_error'),
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choice = $data['choices'][0] ?? null;

            if (! $choice) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            $delta = $choice['delta'] ?? [];

            // Handle error finish reason from OpenRouter...
            if (($choice['finish_reason'] ?? null) === 'error') {
                $error = $choice['error'] ?? [];

                yield (new Error(
                    $this->generateEventId(),
                    (string) ($error['code'] ?? 'provider_error'),
                    $error['message'] ?? 'An upstream provider error occurred.',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $streamModel = $data['model'] ?? $model;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $streamModel,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $reasoningDetails = $this->mergeReasoningDetails($reasoningDetails, $delta['reasoning_details'] ?? []);

            $reasoning = $delta['reasoning'] ?? '';

            if ($reasoning === '') {
                $reasoning = $this->reasoningTextIn($delta['reasoning_details'] ?? []);
            }

            if ($reasoning !== '') {
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
                    $reasoning,
                    time(),
                ))->withInvocationId($invocationId);
            }

            if ($reasoningId !== null && ((isset($delta['content']) && $delta['content'] !== '') || isset($delta['tool_calls']))) {
                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = null;
            }

            if (isset($delta['content']) && $delta['content'] !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $delta['content'];

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $delta['content'],
                    time(),
                ))->withInvocationId($invocationId);
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $idx = $tcDelta['index'];

                    if (! isset($pendingToolCalls[$idx])) {
                        $pendingToolCalls[$idx] = [
                            'id' => $tcDelta['id'] ?? '',
                            'name' => $tcDelta['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    if (isset($tcDelta['function']['arguments'])) {
                        $pendingToolCalls[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($delta['annotations'])) {
                foreach ($delta['annotations'] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $urlCitation = $annotation['url_citation'] ?? [];

                        yield (new CitationEvent(
                            $this->generateEventId(),
                            $messageId,
                            new UrlCitation(
                                $urlCitation['url'] ?? '',
                                $urlCitation['title'] ?? null,
                                isset($urlCitation['start_index']) ? (int) $urlCitation['start_index'] : null,
                                isset($urlCitation['end_index']) ? (int) $urlCitation['end_index'] : null,
                            ),
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }

            if (isset($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        // A closed connection does not establish that the provider finished its response.
        if ($finishReason === null) {
            yield (new Error(
                $this->generateEventId(),
                'incomplete_stream',
                'The provider stream ended before the response was complete.',
                false,
                time(),
            ))->withInvocationId($invocationId);

            return null;
        }

        if ($reasoningId !== null) {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && in_array($finishReason, ['tool_calls', 'stop'], true)) {
            foreach (array_values($pendingToolCalls) as $pending) {
                $toolCall = new ToolCall(
                    $pending['id'] ?? '',
                    $pending['name'] ?? '',
                    json_decode($pending['arguments'] ?? '{}', true) ?? [],
                    $pending['id'] ?? null,
                );

                $toolCalls[] = $toolCall;

                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: filled($toolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason(['finish_reason' => $finishReason]),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $streamModel),
            replayBlocks: array_values($reasoningDetails),
        );
    }

    /**
     * Merge consecutive reasoning fragments while preserving separate blocks.
     *
     * @param array<int, array<string, mixed>> $merged
     * @param array<int, array<string, mixed>> $details
     * @return array<int, array<string, mixed>>
     */
    protected function mergeReasoningDetails(array $merged, array $details): array
    {
        foreach ($details as $detail) {
            $index = array_key_last($merged);
            $type = $detail['type'] ?? null;

            // Streamed blocks can reuse an index; encrypted details are complete, separate blobs.
            if ($index === null
                || ! in_array($type, ['reasoning.text', 'reasoning.summary'], true)
                || ($merged[$index]['type'] ?? null) !== $type
                || (isset($detail['index'], $merged[$index]['index']) && $detail['index'] !== $merged[$index]['index'])) {
                $merged[] = $detail;

                continue;
            }

            foreach ($detail as $key => $value) {
                if (in_array($key, ['text', 'summary'], true) && is_string($value)) {
                    $merged[$index][$key] = ($merged[$index][$key] ?? '') . $value;
                } elseif ($value !== null) {
                    $merged[$index][$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Get the human readable reasoning carried by a delta's reasoning details.
     *
     * @param array<int, array<string, mixed>> $details
     */
    protected function reasoningTextIn(array $details): string
    {
        return implode('', array_map(
            fn (array $detail): string => (string) ($detail['text'] ?? $detail['summary'] ?? ''),
            $details,
        ));
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
