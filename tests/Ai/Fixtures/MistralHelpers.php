<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;

trait MistralHelpers
{
    /**
     * Create a text response.
     */
    protected function fakeTextResponse(string|array $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    /**
     * Create a tool call response.
     */
    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $callId ?? 'call_123',
                        'type' => 'function',
                        'function' => [
                            'name' => $toolName,
                            'arguments' => '{}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    /**
     * Create a structured response.
     */
    protected function fakeStructuredResponse(string $json = '{"symbol": "Au"}'): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $json,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    /**
     * Collect the events from a Mistral agent stream.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream('Hello', provider: 'mistral');

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Encode events as a completed SSE response body.
     */
    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: ' . json_encode($event);
        }

        $lines[] = 'data: [DONE]';

        return implode("\n\n", $lines) . "\n\n";
    }
}
