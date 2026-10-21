<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GroqHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ReasoningTest extends TestCase
{
    use GroqHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testPromptReadsTheParsedReasoningOffTheResponse(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello',
                    'reasoning' => 'Let me think...',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'groq');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    // Groq rejects reasoning_format on non-reasoning and GPT-OSS models, so the caller opts in per model...
    public function testNoReasoningFormatIsSentUnlessTheCallerAsksForOne(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        (new AssistantAgent)->prompt('Hi', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('reasoning_format', $request->data()));
    }

    public function testTheReasoningFormatCanBeSetWithProviderOptions(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['reasoning_format' => 'hidden'];
            }
        };

        $agent->prompt('Hi', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => $request->data()['reasoning_format'] === 'hidden');
    }

    public function testStreamingEmitsReasoningEventsBeforeTheText(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ']),
                $this->chatChunk(['reasoning' => 'think...']),
                $this->chatChunk(['content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $events = $this->collectStreamEvents();

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
}
