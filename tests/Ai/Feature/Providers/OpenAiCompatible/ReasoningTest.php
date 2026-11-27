<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAiCompatible;

use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class ReasoningTest extends TestCase
{
    /**
     * Configure the OpenAI-compatible provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai-compatible', [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => ['text' => ['default' => 'local-model']],
        ]);
    }

    #[DataProvider('reasoningFields')]
    public function testPromptReadsReasoningOffTheResponse(string $field): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'local-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello', $field => 'Let me think...'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        $response = agent()->prompt('Hi', provider: 'openai-compatible');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    #[DataProvider('reasoningFields')]
    public function testStreamingEmitsReasoningEventsBeforeTheText(string $field): void
    {
        $chunk = fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'local-model',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
        ]);

        Http::fake(['*' => Http::response(implode("\n\n", [
            $chunk(['role' => 'assistant', $field => 'Let me ']),
            $chunk([$field => 'think...']),
            $chunk(['content' => 'Hello']),
            $chunk([], 'stop'),
            'data: [DONE]',
        ]) . "\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $events = [];

        foreach (agent()->stream('Hi', provider: 'openai-compatible') as $event) {
            $events[] = $event;
        }

        $this->assertInstanceOf(ReasoningStart::class, $events[1]);
        $this->assertInstanceOf(ReasoningDelta::class, $events[2]);
        $this->assertSame('Let me ', $events[2]->delta);
        $this->assertInstanceOf(ReasoningDelta::class, $events[3]);
        $this->assertSame('think...', $events[3]->delta);
        $this->assertInstanceOf(ReasoningEnd::class, $events[4]);
        $this->assertInstanceOf(TextStart::class, $events[5]);
        $this->assertInstanceOf(TextDelta::class, $events[6]);
        $this->assertSame('Hello', $events[6]->delta);
        $this->assertSame('Let me think...', ReasoningDelta::combine($events));
    }

    #[DataProvider('reasoningFields')]
    public function testToolLoopsSendReasoningBackUnderTheFieldTheServerUsed(string $field): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response($this->toolCallResponse([$field => 'I need a number.'])),
            Http::response($this->textResponse('The number is 72019')),
        ])]);

        (new ProviderOptionsWithToolsAgent)->prompt('Generate a number', provider: 'openai-compatible');

        $this->assertSame([
            'role' => 'assistant',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
            ]],
            $field => 'I need a number.',
        ], $this->replayedToolCallMessage());
    }

    #[DataProvider('reasoningFields')]
    public function testStreamedToolLoopsSendReasoningBackUnderTheFieldTheServerUsed(string $field): void
    {
        $chunk = fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'local-model',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
        ]);

        Http::fake(['*' => Http::sequence([
            Http::response(implode("\n\n", [
                $chunk(['role' => 'assistant', $field => 'I need ']),
                $chunk([$field => 'a number.']),
                $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}']]]]),
                $chunk([], 'tool_calls'),
                'data: [DONE]',
            ]) . "\n\n", 200, ['Content-Type' => 'text/event-stream']),
            Http::response(implode("\n\n", [
                $chunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $chunk([], 'stop'),
                'data: [DONE]',
            ]) . "\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ])]);

        iterator_to_array((new ProviderOptionsWithToolsAgent)->stream('Generate a number', provider: 'openai-compatible'));

        $this->assertSame('I need a number.', $this->replayedToolCallMessage()[$field]);
    }

    /**
     * Provide supported reasoning fields.
     */
    public static function reasoningFields(): array
    {
        return [['reasoning_content'], ['reasoning']];
    }

    public function testToolLoopsSendNoReasoningWhenTheServerReturnedNone(): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response($this->toolCallResponse([])),
            Http::response($this->textResponse('The number is 72019')),
        ])]);

        (new ProviderOptionsWithToolsAgent)->prompt('Generate a number', provider: 'openai-compatible');

        $replayed = $this->replayedToolCallMessage();
        $this->assertArrayNotHasKey('reasoning', $replayed);
        $this->assertArrayNotHasKey('reasoning_content', $replayed);
    }

    /**
     * Build a tool call response carrying the supplied reasoning fields.
     *
     * @param array<string, string> $reasoning
     * @return array<string, mixed>
     */
    private function toolCallResponse(array $reasoning): array
    {
        return [
            'id' => 'chatcmpl-tool',
            'object' => 'chat.completion',
            'model' => 'local-model',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    ...$reasoning,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ];
    }

    /**
     * Build a plain text response.
     *
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'id' => 'chatcmpl-text',
            'object' => 'chat.completion',
            'model' => 'local-model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ];
    }

    /**
     * Extract the replayed assistant tool call message from the second request.
     *
     * @return array<string, mixed>
     */
    private function replayedToolCallMessage(): array
    {
        $messages = json_decode((string) Http::recorded()[1][0]->body(), true)['messages'];

        return collect($messages)->first(fn (array $message): bool => $message['role'] === 'assistant' && isset($message['tool_calls']));
    }
}
