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

    /**
     * Provide supported reasoning fields.
     */
    public static function reasoningFields(): array
    {
        return [['reasoning_content'], ['reasoning']];
    }
}
