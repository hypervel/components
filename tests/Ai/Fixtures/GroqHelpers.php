<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;

trait GroqHelpers
{
    /**
     * Collect the events from a Groq agent stream.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'groq',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Encode events as an SSE response body.
     */
    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = $event === '[DONE]' ? 'data: [DONE]' : 'data: ' . json_encode($event);
        }

        return implode("\n\n", $lines) . "\n\n";
    }

    /**
     * Create a streamed chat delta.
     */
    protected function chatChunk(array $delta, ?string $finishReason = null): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    /**
     * Create a terminal chat chunk with optional usage.
     */
    protected function chatChunkFinish(string $finishReason, ?array $usage = null): array
    {
        $chunk = [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'delta' => (object) [],
                'finish_reason' => $finishReason,
            ]],
        ];

        if ($usage) {
            $chunk['usage'] = $usage;
        }

        return $chunk;
    }

    /**
     * Create the first chunk of a tool call.
     */
    protected function chatChunkToolCallStart(int $index, string $id, string $name): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $index,
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => ''],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ];
    }

    /**
     * Create a tool argument delta.
     */
    protected function chatChunkToolCallDelta(int $index, string $arguments): array
    {
        return [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $index,
                        'function' => ['arguments' => $arguments],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ];
    }
}
