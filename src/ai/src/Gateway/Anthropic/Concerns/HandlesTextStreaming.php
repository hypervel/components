<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Anthropic\Concerns;

use Generator;
use Hypervel\Ai\Gateway\Concerns\StreamsToolInput;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Providers\Provider;
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
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Psr\Http\Message\StreamInterface;

trait HandlesTextStreaming
{
    use StreamsToolInput;

    /**
     * Get the content of the paused steps the turn being continued is made of.
     *
     * @param array<int, Message> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function pausedContent(array $messages): array
    {
        /** @var Collection<int, AssistantMessage> $paused */
        $paused = collect($messages)
            ->reverse()
            ->takeWhile(fn (Message $message): bool => $message instanceof AssistantMessage)
            ->reverse();

        return $paused->flatMap(fn (AssistantMessage $message): array => $message->replayBlocks)->all();
    }

    /**
     * Process a single Anthropic streaming turn and yield Hypervel stream events.
     *
     * @param array<int, array<string, mixed>> $pausedContent
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        StreamInterface $streamBody,
        array $pausedContent = [],
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $reasoningStartEmitted = false;

        $currentBlockType = '';
        $currentBlockIndex = -1;
        $currentBlockText = '';
        $currentThinkingText = '';
        $currentSignature = '';
        $currentToolIndex = -1;
        $currentServerToolInput = '';
        $pendingToolCalls = [];
        $hasInvalidToolInput = false;
        $responseContent = [];
        $afterTool = false;
        $lastTextDelta = '';

        // A turn resumed after pause_turn picks up where the paused content ended...
        foreach ($pausedContent as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'text' && ($block['text'] ?? '') !== '') {
                $lastTextDelta = (string) $block['text'];
                $afterTool = false;
            } elseif ($this->isToolBlock($blockType)) {
                $afterTool = true;
            }
        }

        $messageUsage = [];
        $stopReason = '';
        $container = null;
        $citedUrls = [];

        $emitTextStart = function () use (&$textStartEmitted, $messageId, $invocationId): ?StreamEvent {
            if ($textStartEmitted) {
                return null;
            }

            $textStartEmitted = true;

            return (new TextStart(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningStartEmitted, &$reasoningId, $invocationId): ?StreamEvent {
            if ($reasoningStartEmitted) {
                return null;
            }

            $reasoningStartEmitted = true;
            $reasoningId = $this->generateEventId();

            return (new ReasoningStart(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        };

        $emitCitation = function (UrlCitation $citation) use (&$citedUrls, $messageId, $invocationId): ?StreamEvent {
            if (in_array($citation->url, $citedUrls, true)) {
                return null;
            }

            $citedUrls[] = $citation->url;

            return (new CitationEvent(
                $this->generateEventId(),
                $messageId,
                $citation,
                time(),
            ))->withInvocationId($invocationId);
        };

        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $type = $data['type'] ?? '';

            if ($type === 'error') {
                yield (new Error(
                    $this->generateEventId(),
                    $data['error']['type'] ?? 'unknown_error',
                    $data['error']['message'] ?? 'Unknown error',
                    false,
                    time(),
                    usage: $messageUsage === [] ? null : $this->extractUsage(['usage' => $messageUsage]),
                ))->withInvocationId($invocationId);

                return;
            }

            if ($type === 'message_start' && ! $streamStartEmitted) {
                $streamStartEmitted = true;

                $messageUsage = $data['message']['usage'] ?? [];

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $data['message']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'content_block_start') {
                $blockType = $data['content_block']['type'] ?? '';
                $currentBlockType = $blockType;
                $currentBlockIndex = $data['index'] ?? count($responseContent);

                if ($this->isToolBlock($blockType)) {
                    $afterTool = true;
                }

                if ($blockType === 'text') {
                    $currentBlockText = '';

                    if (($event = $emitTextStart()) instanceof StreamEvent) {
                        yield $event;
                    }
                } elseif ($blockType === 'thinking') {
                    $currentThinkingText = '';
                    $currentSignature = '';

                    if (($event = $emitReasoningStart()) instanceof StreamEvent) {
                        yield $event;
                    }
                } elseif ($blockType === 'tool_use') {
                    ++$currentToolIndex;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $data['content_block']['id'] ?? '',
                        'name' => $data['content_block']['name'] ?? '',
                        'arguments' => '',
                    ];

                    // Unlike upstream, keep the synthetic structured-output tool internal during streaming.
                    if ($pendingToolCalls[$currentToolIndex]['name'] !== 'output_structured_data') {
                        yield from $this->toolInputStart($invocationId, $pendingToolCalls[$currentToolIndex]['id'], $pendingToolCalls[$currentToolIndex]['name']);
                    }
                } elseif ($blockType === 'server_tool_use') {
                    $currentServerToolInput = '';

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'started',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                } elseif ($this->isProviderToolResultBlock($blockType)) {
                    $fetchResult = $data['content_block']['content'] ?? [];

                    if ($blockType === 'web_fetch_tool_result' && ($fetchResult['type'] ?? '') === 'web_fetch_result' && filled($fetchResult['url'] ?? null)
                        && ($event = $emitCitation(new UrlCitation($fetchResult['url'], $fetchResult['content']['title'] ?? null))) instanceof StreamEvent) {
                        yield $event;
                    }

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $data['content_block']['tool_use_id'] ?? $data['content_block']['id'] ?? '',
                        $blockType,
                        $data['content_block'] ?? [],
                        'result_received',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                }

                // Track content blocks for tool loop replay...
                if (isset($data['content_block'])) {
                    $responseContent[$data['index'] ?? count($responseContent)] = $data['content_block'];
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $deltaType = $data['delta']['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $textDelta = (string) ($data['delta']['text'] ?? '');

                    if ($textDelta !== '') {
                        $separator = $afterTool && $lastTextDelta !== '' ? $this->textSeparator($lastTextDelta, $textDelta) : '';
                        $afterTool = false;
                        $lastTextDelta = $textDelta;

                        if (($event = $emitTextStart()) instanceof StreamEvent) {
                            yield $event;
                        }

                        $currentBlockText .= $textDelta;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $separator . $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'thinking_delta') {
                    $delta = (string) ($data['delta']['thinking'] ?? '');

                    if ($delta !== '') {
                        if (($event = $emitReasoningStart()) instanceof StreamEvent) {
                            yield $event;
                        }

                        $currentThinkingText .= $delta;

                        yield (new ReasoningDelta(
                            $this->generateEventId(),
                            $reasoningId,
                            $delta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    $currentSignature .= (string) ($data['delta']['signature'] ?? '');
                } elseif ($deltaType === 'citations_delta' && $currentBlockType === 'text') {
                    $citationData = $data['delta']['citation'] ?? null;

                    // Unlike upstream, the citation is kept on its block, as in a buffered response,
                    // so the step's citations and replay content include it.
                    if ($citationData && isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['citations'][] = $citationData;
                    }

                    if ($citationData && ($citationData['type'] ?? '') === 'web_search_result_location'
                        && ($event = $emitCitation(new UrlCitation($citationData['url'] ?? '', $citationData['title'] ?? null))) instanceof StreamEvent) {
                        yield $event;
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $partial = (string) ($data['delta']['partial_json'] ?? '');

                    if ($currentBlockType === 'tool_use' && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;

                        if ($pendingToolCalls[$currentToolIndex]['name'] !== 'output_structured_data') {
                            yield from $this->toolInputDelta($invocationId, $pendingToolCalls[$currentToolIndex]['id'], $pendingToolCalls[$currentToolIndex]['name'], $partial);
                        }
                    } elseif ($currentBlockType === 'server_tool_use') {
                        $currentServerToolInput .= $partial;
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop') {
                if ($currentBlockType === 'text') {
                    // The block closes, the message does not. Anthropic opens a text block per
                    // citable span, so one answer arrives as several; the replay content keeps
                    // them apart while the stream reports the step as a single message...
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['text'] = $currentBlockText;
                    }
                } elseif ($currentBlockType === 'thinking' && $reasoningStartEmitted) {
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['thinking'] = $currentThinkingText;
                        $responseContent[$currentBlockIndex]['signature'] = $currentSignature;
                    }

                    yield (new ReasoningEnd(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);

                    $reasoningStartEmitted = false;
                    $reasoningId = '';
                } elseif ($currentBlockType === 'tool_use' && isset($pendingToolCalls[$currentToolIndex])) {
                    $call = $pendingToolCalls[$currentToolIndex];
                    $index = $data['index'] ?? $currentBlockIndex;

                    if ($call['name'] === 'output_structured_data') {
                        if (isset($responseContent[$index])) {
                            $responseContent[$index]['input'] = json_decode($call['arguments'] ?: '{}', true) ?? [];
                        }
                    } elseif ($this->isCompleteToolInput($call['arguments'])) {
                        $parsedArguments = json_decode($call['arguments'] ?: '{}', true) ?? [];

                        if (isset($responseContent[$index])) {
                            $responseContent[$index]['input'] = $parsedArguments;
                        }

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            new ToolCall(
                                $call['id'],
                                $call['name'],
                                $parsedArguments,
                                $call['id'],
                            ),
                            time(),
                        ))->withInvocationId($invocationId);
                    } else {
                        // Unlike upstream, eager input streaming skips Anthropic's JSON validation, so invalid input must never run with [].
                        $hasInvalidToolInput = true;
                        unset($responseContent[$index]);
                    }
                } elseif ($currentBlockType === 'server_tool_use') {
                    $index = $data['index'] ?? count($responseContent) - 1;

                    if ($currentServerToolInput !== '' && isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = json_decode($currentServerToolInput, true) ?? [];
                    }

                    yield (new ProviderToolEvent(
                        $this->generateEventId(),
                        $responseContent[$index]['id'] ?? '',
                        $currentBlockType,
                        $responseContent[$index] ?? [],
                        'completed',
                        time(),
                        provider: $provider->name(),
                    ))->withInvocationId($invocationId);
                }

                $currentBlockType = '';

                continue;
            }

            if ($type === 'message_delta') {
                $stopReason = $data['delta']['stop_reason'] ?? '';
                $container = $data['delta']['container']['id'] ?? $container;

                // Usage on message_delta is cumulative for the whole message...
                $messageUsage = array_merge($messageUsage, $data['usage'] ?? []);
            }
        }

        // A successful HTTP transfer can still contain an unfinished provider response.
        if ($stopReason === '') {
            yield (new Error(
                $this->generateEventId(),
                'incomplete_stream',
                'The provider stream ended before the response was complete.',
                false,
                time(),
                usage: $messageUsage === [] ? null : $this->extractUsage(['usage' => $messageUsage]),
            ))->withInvocationId($invocationId);

            return;
        }

        // Closed once the step is over rather than once per block, so the step is one message...
        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        $finishReason = $this->extractFinishReason(['stop_reason' => $stopReason]);

        $step = $this->buildStepResponse(
            content: array_values($responseContent),
            provider: $provider,
            model: $model,
            usage: $this->extractUsage(['usage' => $messageUsage]),
            finishReason: $finishReason,
            structured: false,
            container: $container,
        );

        if ($hasInvalidToolInput && $step->toolCalls === []) {
            // Without a remaining tool call, replaying raw blocks risks leaving a trailing thinking block Anthropic rejects.
            $step->replayBlocks = [];
        }

        return $step;
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
