<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi\Concerns;

use Generator;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
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

trait HandlesTextGeneration
{
    /**
     * Process streamed response events and return the completed step.
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
        $responseId = '';
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $reasoningItems = [];
        $usage = null;
        $responseData = [];

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error' || $type === 'response.failed') {
                $error = $type === 'response.failed'
                    ? ($data['response']['error'] ?? [])
                    : ($data['error'] ?? []);

                yield (new Error(
                    $this->generateEventId(),
                    $error['code'] ?? $error['type'] ?? $data['code'] ?? 'unknown_error',
                    $error['message'] ?? $data['message'] ?? 'Unknown error',
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            if ($type === 'response.created' && ! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseId = $data['response']['id'] ?? '';

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['response']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'response.output_text.delta') {
                $textDelta = (string) ($data['delta'] ?? '');
                $currentText .= $textDelta;

                if ($textDelta !== '') {
                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    yield (new TextDelta(
                        $this->generateEventId(),
                        $messageId,
                        $textDelta,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($type === 'response.output_text.annotation.added') {
                $annotation = $data['annotation'] ?? [];

                if (($annotation['type'] ?? '') === 'url_citation') {
                    yield (new CitationEvent(
                        $this->generateEventId(),
                        $messageId,
                        new UrlCitation(
                            $annotation['url'] ?? '',
                            $annotation['title'] ?? null,
                            isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                            isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                        ),
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($type === 'response.output_text.done' && $textStartEmitted) {
                yield (new TextEnd(
                    $this->generateEventId(),
                    $messageId,
                    time(),
                ))->withInvocationId($invocationId);

                $textStartEmitted = false;
                $messageId = $this->generateEventId();

                continue;
            }

            if (in_array($type, ['response.reasoning_summary_text.delta', 'response.reasoning_text.delta'], true)) {
                $delta = (string) ($data['delta'] ?? '');

                if ($delta !== '') {
                    if ($reasoningId === '') {
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
                        $delta,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($type === 'response.output_item.done' && ($data['item']['type'] ?? '') === 'reasoning') {
                $reasoningItems[] = [
                    'id' => $data['item']['id'] ?? null,
                    'summary' => $data['item']['summary'] ?? [],
                    'encrypted_content' => $data['item']['encrypted_content'] ?? null,
                ];

                if ($reasoningId !== '') {
                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningId = '';
                }

                continue;
            }

            if ($type === 'response.output_item.done') {
                $itemType = $data['item']['type'] ?? '';

                if ($itemType !== 'function_call' && str_ends_with((string) $itemType, '_call')) {
                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['item']['id'] ?? '',
                        $itemType,
                        $data['item'] ?? [],
                        'completed',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);

                    continue;
                }
            }

            if (preg_match('/^response\.([a-z_]+_call)(_code)?\.(.+)$/', (string) $type, $matches) === 1) {
                yield (new ProviderToolEvent(
                    $this->generateEventId(),
                    $data['item_id'] ?? '',
                    $matches[1],
                    $data,
                    $matches[2] === '' ? $matches[3] : 'code_' . $matches[3],
                    time(),
                    provider: $provider->name(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if (($data['item']['type'] ?? '') === 'function_call' && $type === 'response.output_item.added') {
                $index = (int) ($data['output_index'] ?? count($pendingToolCalls));

                $toolCall = [
                    'id' => $data['item']['id'] ?? null,
                    'call_id' => $data['item']['call_id'] ?? null,
                    'name' => $data['item']['name'] ?? null,
                    'arguments' => '',
                ];

                if (filled($reasoningItems)) {
                    $latestReasoning = end($reasoningItems);

                    $toolCall['reasoning_id'] = $latestReasoning['id'];
                    $toolCall['reasoning_summary'] = $latestReasoning['summary'] ?? [];
                    $toolCall['reasoning_encrypted_content'] = $latestReasoning['encrypted_content'] ?? null;
                }

                $pendingToolCalls[$index] = $toolCall;

                continue;
            }

            if ($type === 'response.function_call_arguments.delta') {
                $callId = $data['item_id'] ?? null;

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        $call['arguments'] .= $data['delta'] ?? '';
                        break;
                    }
                }

                unset($call);

                continue;
            }

            if ($type === 'response.function_call_arguments.done') {
                $callId = $data['item_id'] ?? null;
                $arguments = $data['arguments'] ?? '';

                foreach ($pendingToolCalls as &$call) {
                    if (($call['id'] ?? null) === $callId) {
                        if ($arguments !== '') {
                            $call['arguments'] = $arguments;
                        }

                        $toolCall = new ToolCall(
                            $call['id'],
                            $call['name'],
                            json_decode($call['arguments'], true) ?? [],
                            $call['call_id'] ?? null,
                            $call['reasoning_id'] ?? null,
                            $call['reasoning_summary'] ?? null,
                            $call['reasoning_encrypted_content'] ?? null,
                        );

                        $toolCalls[] = $toolCall;

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            $toolCall,
                            time(),
                        ))->withInvocationId($invocationId);

                        break;
                    }
                }

                unset($call);

                continue;
            }

            if ($type === 'response.completed' || $type === 'response.incomplete') {
                $response = $data['response'] ?? [];
                $responseData = $response;
                $responseId = $response['id'] ?? $responseId;

                $usage = $this->extractUsage($response);
            }
        }

        // A successful HTTP transfer can still contain an unfinished provider response.
        if ($responseData === []) {
            yield (new Error(
                $this->generateEventId(),
                'incomplete_stream',
                'The provider stream ended before the response was complete.',
                false,
                time(),
            ))->withInvocationId($invocationId);

            return null;
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($responseData),
            usage: $usage ?? new TextUsage(0, 0),
            meta: new Meta($provider->name(), $responseData['model'] ?? $model),
            continuationToken: $responseId,
            replayBlocks: $this->extractReplayBlocks($responseData['output'] ?? []),
            providerToolCalls: $this->extractProviderToolCalls($responseData['output'] ?? []),
        );
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
