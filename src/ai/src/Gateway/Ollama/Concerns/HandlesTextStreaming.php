<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Generator;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
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
        $pendingToolCalls = [];
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

            // Tool call indexes span chunks, while array positions restart in each chunk.
            // Retain positional merging for fragments that omit an explicit index.
            if (! empty($data['message']['tool_calls'])) {
                foreach ($data['message']['tool_calls'] as $index => $toolCall) {
                    $index = $toolCall['function']['index'] ?? $index;

                    if (! isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index] = [
                            'id' => null,
                            'name' => '',
                            'arguments' => [],
                            'argumentsBuffer' => '',
                        ];
                    }

                    if (isset($toolCall['id'])) {
                        $pendingToolCalls[$index]['id'] = $toolCall['id'];
                    }

                    $name = $toolCall['function']['name'] ?? '';

                    if ($name !== '') {
                        $pendingToolCalls[$index]['name'] = $name;
                    }

                    if (array_key_exists('arguments', $toolCall['function'] ?? [])) {
                        $arguments = $toolCall['function']['arguments'];

                        if (is_array($arguments)) {
                            $pendingToolCalls[$index]['arguments'] = array_replace(
                                $pendingToolCalls[$index]['arguments'],
                                $arguments
                            );
                        } elseif (is_string($arguments)) {
                            $pendingToolCalls[$index]['argumentsBuffer'] .= $arguments;
                        }
                    }
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

        if (filled($pendingToolCalls)) {
            $toolCalls = array_map(function (array $toolCall): ToolCall {
                $arguments = $toolCall['arguments'];

                if ($toolCall['argumentsBuffer'] !== '') {
                    $decoded = json_decode((string) $toolCall['argumentsBuffer'], true);

                    if (is_array($decoded)) {
                        $arguments = array_replace($arguments, $decoded);
                    }
                }

                $id = $toolCall['id'] ?? (string) Str::uuid7();

                return new ToolCall($id, $toolCall['name'], $arguments, $id);
            }, array_values($pendingToolCalls));

            foreach ($toolCalls as $toolCall) {
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
            finishReason: filled($toolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason($lastData),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $lastData['model'] ?? $model),
        );
    }

    /**
     * Parse an Ollama NDJSON stream, yielding each parsed JSON object.
     */
    protected function parseNdjsonStream(StreamInterface $streamBody): Generator
    {
        $buffer = '';

        while (! $streamBody->eof()) {
            $buffer .= $streamBody->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

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

        if (filled(trim($buffer))) {
            $data = json_decode(trim($buffer), true);

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
