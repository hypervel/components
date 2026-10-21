<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;

trait XaiHelpers
{
    /**
     * Create a completed text response.
     */
    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text, 'annotations' => []],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    /**
     * Create a text response with reasoning items.
     */
    protected function fakeReasonedTextResponse(array $reasoningItems, string $text = 'Answer', bool $reasoningFirst = false): PromiseInterface
    {
        $message = [
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [
                ['type' => 'output_text', 'text' => $text, 'annotations' => []],
            ],
        ];

        return Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => $reasoningFirst ? [...$reasoningItems, $message] : [$message, ...$reasoningItems],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 3],
            ],
        ]);
    }

    /**
     * Create a tool call response.
     */
    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        $callId ??= 'call_123';

        return Http::response([
            'id' => 'resp_tool_' . uniqid(),
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_' . uniqid(),
                    'call_id' => $callId,
                    'name' => $toolName,
                    'arguments' => '{}',
                    'status' => 'completed',
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    /**
     * Create a structured text response.
     */
    protected function fakeStructuredResponse(string $json = '{"symbol": "Au"}'): PromiseInterface
    {
        return $this->fakeTextResponse($json);
    }

    /**
     * Collect an agent's xAI stream events.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream('Hello', provider: 'xai');

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

        $lines[] = 'data: [DONE]';

        return implode("\n\n", $lines) . "\n\n";
    }
}
