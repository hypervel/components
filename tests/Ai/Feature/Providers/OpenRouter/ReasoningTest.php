<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\OpenRouterHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ReasoningTest extends TestCase
{
    use OpenRouterHelpers;

    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    /**
     * Create a reasoned text response.
     */
    protected function fakeOpenRouterReasonedResponse(array $message): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello', ...$message],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
    }

    public function testPromptReadsThePlaintextReasoningOffTheResponse(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterReasonedResponse(['reasoning' => 'Let me think...'])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'openrouter');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    public function testPromptFallsBackToTheReasoningDetailsWhenNoPlaintextReasoningIsSent(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterReasonedResponse(['reasoning_details' => [
            ['type' => 'reasoning.text', 'text' => 'First.', 'index' => 0],
            ['type' => 'reasoning.summary', 'summary' => 'Second.', 'index' => 1],
            ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'index' => 2],
        ]])]);

        $this->assertSame("First.\n\nSecond.", (new AssistantAgent)->prompt('Hi', provider: 'openrouter')->reasoning);
    }

    public function testStreamingEmitsReasoningEvents(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
                $this->chatChunk(['reasoning' => 'think...', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...', 'signature' => 'sig']]]),
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

    public function testStreamedReasoningDetailsDriveTheReasoningEventsWhenNoPlaintextReasoningIsSent(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...']]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.summary', 'index' => 1, 'summary' => 'I decided.']]]),
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
        $this->assertInstanceOf(ReasoningDelta::class, $events[4]);
        $this->assertSame('I decided.', $events[4]->delta);
        $this->assertInstanceOf(ReasoningEnd::class, $events[5]);
        $this->assertInstanceOf(TextStart::class, $events[6]);
        $this->assertSame('Let me think...I decided.', ReasoningDelta::combine($events));
    }

    public function testAnEncryptedReasoningDetailDrivesNoReasoningEvents(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.encrypted', 'index' => 0, 'data' => 'ciphertext']]]),
                $this->chatChunk(['content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $events = $this->collectStreamEvents();

        $this->assertEmpty(collect($events)->whereInstanceOf(ReasoningStart::class));
        $this->assertSame('', ReasoningDelta::combine($events));
    }

    /**
     * Get the assistant tool-call message from the follow-up request.
     */
    protected function openRouterFollowUpAssistantMessage(): array
    {
        $requests = Http::recorded();
        $messages = json_decode((string) $requests[1][0]->body(), true)['messages'];

        return collect($messages)->first(fn (array $message): bool => $message['role'] === 'assistant' && isset($message['tool_calls']));
    }

    #[DataProvider('toolFinishReasons')]
    public function testPromptReplaysTheReasoningDetailsWithTheToolCallsTheyProduced(string $finishReason): void
    {
        $details = [
            ['type' => 'reasoning.summary', 'summary' => 'I need a number.', 'format' => 'openai-responses-v1', 'index' => 0],
            ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => 1],
        ];

        Http::fake(['*' => Http::sequence([
            Http::response([
                'id' => 'chatcmpl-tool-123',
                'object' => 'chat.completion',
                'model' => 'openai/gpt-5.6',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'reasoning_details' => $details,
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => $finishReason,
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
            fakeOpenRouterResponse('The number is 72019'),
        ])]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $this->assertSame($details, $this->openRouterFollowUpAssistantMessage()['reasoning_details']);
    }

    /**
     * Provide the terminal reasons returned with complete tool calls.
     */
    public static function toolFinishReasons(): array
    {
        return [['tool_calls'], ['stop']];
    }

    #[DataProvider('reasoningIndices')]
    public function testStreamingReplaysTheMergedReasoningDetailsWithTheToolCallsTheyProduced(int $firstIndex, int $secondIndex, string $finishReason): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.summary', 'summary' => 'I need ', 'format' => 'openai-responses-v1', 'index' => 0]]]),
                    $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.summary', 'summary' => 'a number.', 'format' => 'openai-responses-v1', 'index' => 0]]]),
                    $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => $firstIndex]]]),
                    $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.encrypted', 'data' => 'second', 'id' => 'rs_456', 'format' => 'openai-responses-v1', 'index' => $secondIndex]]]),
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish($finishReason, ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ])]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $this->assertSame([
            ['type' => 'reasoning.summary', 'summary' => 'I need a number.', 'format' => 'openai-responses-v1', 'index' => 0],
            ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => $firstIndex],
            ['type' => 'reasoning.encrypted', 'data' => 'second', 'id' => 'rs_456', 'format' => 'openai-responses-v1', 'index' => $secondIndex],
        ], $this->openRouterFollowUpAssistantMessage()['reasoning_details']);
    }

    /**
     * Provide distinct and repeated wire indices.
     */
    public static function reasoningIndices(): array
    {
        return [[1, 2, 'tool_calls'], [0, 0, 'tool_calls'], [1, 2, 'stop']];
    }

    public function testStreamingKeepsASignatureThatArrivesAfterTheReasoningText(): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'text' => 'Let me ', 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                    $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'text' => 'think.', 'signature' => null, 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                    $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'text' => '', 'signature' => 'sig', 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ])]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $this->assertSame([
            ['type' => 'reasoning.text', 'text' => 'Let me think.', 'format' => 'anthropic-claude-v1', 'index' => 0, 'signature' => 'sig'],
        ], $this->openRouterFollowUpAssistantMessage()['reasoning_details']);
    }

    public function testAToolCallWithoutReasoningDetailsIsReplayedWithAnEmptyList(): void
    {
        Http::fake(['*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ])]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $this->assertSame([], $this->openRouterFollowUpAssistantMessage()['reasoning_details'] ?? null);
    }

    public function testStreamingPreservesDistinctReasoningIndicesAndMergesUnindexedFragments(): void
    {
        Http::fake(['*' => Http::sequence([
            Http::response($this->ssePayload([
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'A']]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'index' => 1, 'text' => 'B']]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'text' => 'C']]]),
                $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls'),
            ])),
            Http::response($this->ssePayload([$this->chatChunk(['content' => 'Answer']), $this->chatChunkFinish('stop')])),
        ])]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $this->assertSame([
            ['type' => 'reasoning.text', 'index' => 0, 'text' => 'A'],
            ['type' => 'reasoning.text', 'index' => 1, 'text' => 'BC'],
        ], $this->openRouterFollowUpAssistantMessage()['reasoning_details']);
    }
}
