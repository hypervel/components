<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;

trait OpenAiHelpers
{
    /**
     * Collect an agent's OpenAI stream events.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'openai',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Encode events as an SSE payload.
     */
    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: ' . json_encode($event);
        }

        return implode("\n\n", $lines) . "\n\n";
    }

    /**
     * Build a response creation event.
     */
    protected function responseCreated(): array
    {
        return [
            'type' => 'response.created',
            'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-5.4',
                'status' => 'in_progress',
                'output' => [],
            ],
        ];
    }

    /**
     * Build a text delta event.
     */
    protected function outputTextDelta(string $text): array
    {
        return [
            'type' => 'response.output_text.delta',
            'delta' => $text,
            'item_id' => 'msg_1',
            'output_index' => 0,
            'content_index' => 0,
        ];
    }

    /**
     * Build a text completion event.
     */
    protected function outputTextDone(string $text): array
    {
        return [
            'type' => 'response.output_text.done',
            'text' => $text,
            'item_id' => 'msg_1',
            'output_index' => 0,
            'content_index' => 0,
        ];
    }

    /**
     * Build a function call start event.
     */
    protected function outputItemAdded(string $id, string $callId, string $name): array
    {
        return [
            'type' => 'response.output_item.added',
            'output_index' => 0,
            'item' => [
                'type' => 'function_call',
                'id' => $id,
                'call_id' => $callId,
                'name' => $name,
                'arguments' => '',
                'status' => 'in_progress',
            ],
        ];
    }

    /**
     * Build a function argument delta event.
     */
    protected function functionCallArgumentsDelta(string $itemId, string $delta): array
    {
        return [
            'type' => 'response.function_call_arguments.delta',
            'item_id' => $itemId,
            'delta' => $delta,
            'output_index' => 0,
        ];
    }

    /**
     * Build a function argument completion event.
     */
    protected function functionCallArgumentsDone(string $itemId, string $arguments): array
    {
        return [
            'type' => 'response.function_call_arguments.done',
            'item_id' => $itemId,
            'arguments' => $arguments,
            'output_index' => 0,
        ];
    }

    /**
     * Build a completed response event with usage and output.
     */
    protected function responseCompleted(int $inputTokens, int $outputTokens, int $cachedTokens = 0, int $reasoningTokens = 0, int $cacheWriteTokens = 0, ?array $output = null): array
    {
        return [
            'type' => 'response.completed',
            'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-5.4',
                'status' => 'completed',
                'output' => $output ?? [
                    [
                        'type' => 'message',
                        'status' => 'completed',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => '']],
                    ],
                ],
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'input_tokens_details' => [
                        'cached_tokens' => $cachedTokens,
                        'cache_write_tokens' => $cacheWriteTokens,
                    ],
                    'output_tokens_details' => [
                        'reasoning_tokens' => $reasoningTokens,
                    ],
                ],
            ],
        ];
    }
}
