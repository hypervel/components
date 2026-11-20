<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Generator;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
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
use Hypervel\Http\Client\LineReader;
use Hypervel\Support\Str;
use Psr\Http\Message\StreamInterface;

trait HandlesTextStreaming
{
    /**
     * Process an Ollama NDJSON streaming response and yield Hypervel stream events.
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
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $usage = null;
        $lastData = [];

        foreach ($this->parseNdjsonStream($streamBody) as $data) {
            if (isset($data['error'])) {
                $error = $data['error'];
                $isStructured = is_array($error);

                yield (new Error(
                    $this->generateEventId(),
                    $isStructured ? ($error['code'] ?? 'unknown_error') : 'unknown_error',
                    $isStructured ? ($error['message'] ?? 'Unknown error') : (string) $error,
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $thinking = $data['message']['thinking'] ?? '';
            $content = $data['message']['content'] ?? '';

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

            if ($reasoningId !== null && ($content !== '' || ! empty($data['message']['tool_calls']))) {
                yield (new ReasoningEnd(
                    $this->generateEventId(),
                    $reasoningId,
                    time(),
                ))->withInvocationId($invocationId);

                $reasoningId = null;
            }

            if ($content !== '') {
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

            foreach ($data['message']['tool_calls'] ?? [] as $toolCall) {
                if (is_array($toolCall['function'] ?? null)) {
                    $toolCalls[] = $this->mapToolCall($toolCall);
                }
            }

            if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
                $usage = $this->extractUsage($data);
            }

            if ($data['done'] ?? false) {
                $lastData = $data;

                break;
            }
        }

        // Only the done chunk confirms that the response and its tool calls are complete.
        if ($lastData === []) {
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

        foreach ($toolCalls as $toolCall) {
            yield (new ToolCallEvent(
                $this->generateEventId(),
                $toolCall,
                time(),
            ))->withInvocationId($invocationId);
        }

        $finishReason = $this->extractFinishReason($lastData);

        // Ollama reports stop alongside complete tool calls; a length stop truncated the response, so its calls must not run.
        if ($finishReason === FinishReason::Stop && $toolCalls !== []) {
            $finishReason = FinishReason::ToolCalls;
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $lastData['model'] ?? $model),
        );
    }

    /**
     * Parse an Ollama NDJSON stream, yielding each parsed JSON object.
     */
    protected function parseNdjsonStream(StreamInterface $streamBody): Generator
    {
        foreach (LineReader::lines($streamBody) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);

            if ($data !== null) {
                yield $data;
            }
        }
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
