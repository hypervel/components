<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\HistoricalReasoningWithoutToolCallsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\HistoricalToolCallWithEmptyReasoningAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\HistoricalToolCallWithoutReasoningAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\HistoricalToolCallWithReasoningAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\DeepSeekHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ReasoningTest extends TestCase
{
    use DeepSeekHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testPromptReadsReasoningContentOffTheResponse(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'id' => 'chatcmpl-deepseek-123',
                'object' => 'chat.completion',
                'model' => 'deepseek-reasoner',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello',
                        'reasoning_content' => 'Let me think...',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $this->assertSame('Let me think...', (new AssistantAgent)->prompt('Hi', provider: 'deepseek')->reasoning);
    }

    public function testPreservesReasoningContentAcrossToolCallLoops(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                Http::response([
                    'id' => 'chatcmpl-deepseek-tool-123',
                    'object' => 'chat.completion',
                    'model' => 'deepseek-chat',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'reasoning_content' => 'Let me think. I need to call the FixedNumberGenerator.',
                            'tool_calls' => [[
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'FixedNumberGenerator',
                                    'arguments' => '{}',
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ]),
                fakeDeepSeekResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'deepseek');

        $followUpMessages = $this->requestMessages(1);
        $assistantMsg = $this->findMessage($followUpMessages, role: 'assistant', has: 'tool_calls');

        $this->assertSame('Let me think. I need to call the FixedNumberGenerator.', $assistantMsg['reasoning_content']);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
    }

    public function testEmitsReasoningStartDeltaAndEndEventsWhileStreaming(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Hmm, let']),
                    $this->chatChunk(['reasoning_content' => ' me think.']),
                    $this->chatChunk(['content' => 'Hello']),
                    $this->chatChunk(['content' => ' world']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(ReasoningStart::class, $events[1]);
        $this->assertInstanceOf(ReasoningDelta::class, $events[2]);
        $this->assertSame('Hmm, let', $events[2]->delta);
        $this->assertInstanceOf(ReasoningDelta::class, $events[3]);
        $this->assertSame(' me think.', $events[3]->delta);
        $this->assertInstanceOf(ReasoningEnd::class, $events[4]);
        $this->assertInstanceOf(TextStart::class, $events[5]);
        $this->assertInstanceOf(TextDelta::class, $events[6]);
        $this->assertSame('Hello', $events[6]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[7]);
        $this->assertSame(' world', $events[7]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[8]);
        $this->assertInstanceOf(StreamEnd::class, $events[9]);
    }

    public function testPreservesReasoningContentAcrossStreamingToolCallLoops(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Thinking process...']),
                        $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                        $this->chatChunkToolCallDelta(0, '{}'),
                        $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                        $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $followUpMessages = $this->requestMessages(1);
        $assistantMsg = $this->findMessage($followUpMessages, role: 'assistant', has: 'tool_calls');

        $this->assertSame('Thinking process...', $assistantMsg['reasoning_content']);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
    }

    public function testStripsReasoningFromDeepSeekReasonerHistoricalMessagesWithoutToolCalls(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'id' => 'chatcmpl-reasoner-2',
                'object' => 'chat.completion',
                'model' => 'deepseek-reasoner',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'The answer is 6.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
            ]),
        ]);

        (new HistoricalReasoningWithoutToolCallsAgent)->prompt('What is 3+3?', provider: 'deepseek', model: 'deepseek-reasoner');

        $assistantMsg = $this->findMessage($this->requestMessages(0), role: 'assistant');

        $this->assertSame('The answer is 8.', $assistantMsg['content']);
        $this->assertArrayNotHasKey('reasoning_content', $assistantMsg);
        $this->assertArrayNotHasKey('tool_calls', $assistantMsg);
    }

    public function testDefaultsReasoningContentToEmptyStringWhenHistoricalReasoningIsEmpty(): void
    {
        Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Here you go.')]);

        (new HistoricalToolCallWithEmptyReasoningAgent)->prompt('tell me more', provider: 'deepseek');

        $messages = $this->requestMessages(0);
        $assistantMsg = $this->findMessage($messages, role: 'assistant');

        $this->assertSame('Found the products.', $assistantMsg['content']);
        $this->assertSame('', $assistantMsg['reasoning_content']);
        $this->assertCount(1, $assistantMsg['tool_calls']);
        $this->assertSame('SearchProducts', $assistantMsg['tool_calls'][0]['function']['name']);

        $this->assertCount(1, $this->filterMessages($messages, 'tool'));
    }

    public function testDefaultsReasoningContentToEmptyStringWhenHistoricalReasoningIsMissing(): void
    {
        Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Sure, here is the info.')]);

        (new HistoricalToolCallWithoutReasoningAgent)->prompt('tell me more', provider: 'deepseek');

        $messages = $this->requestMessages(0);
        $assistantMsg = $this->findMessage($messages, role: 'assistant');

        $this->assertSame('Found the products.', $assistantMsg['content']);
        $this->assertSame('', $assistantMsg['reasoning_content']);
        $this->assertCount(1, $assistantMsg['tool_calls']);
        $this->assertSame('SearchProducts', $assistantMsg['tool_calls'][0]['function']['name']);

        $this->assertCount(1, $this->filterMessages($messages, 'tool'));
    }

    public function testPreservesToolCallsWhenHistoricalReasoningContentIsPresent(): void
    {
        Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Here you go.')]);

        (new HistoricalToolCallWithReasoningAgent)->prompt('tell me more', provider: 'deepseek');

        $messages = $this->requestMessages(0);
        $assistantMsg = $this->findMessage($messages, role: 'assistant');

        $this->assertSame('Found the products.', $assistantMsg['content']);
        $this->assertSame('I need to search for lampu products.', $assistantMsg['reasoning_content']);
        $this->assertCount(1, $assistantMsg['tool_calls']);
        $this->assertSame('SearchProducts', $assistantMsg['tool_calls'][0]['function']['name']);

        $this->assertCount(1, $this->filterMessages($messages, 'tool'));
    }
}
