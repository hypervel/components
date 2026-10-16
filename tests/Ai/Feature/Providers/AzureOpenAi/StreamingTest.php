<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\AzureOpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use AzureOpenAiHelpers;

    /**
     * Configure the Azure OpenAI deployment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://my-resource.cognitiveservices.azure.com',
            'deployment' => 'gpt-4o',
        ]);
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDelta(' world'),
                    $this->outputTextDone('Hello world'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(TextStart::class, $events[1]);
        $this->assertInstanceOf(TextDelta::class, $events[2]);
        $this->assertSame('Hello', $events[2]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[3]);
        $this->assertSame(' world', $events[3]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[4]);
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }

    public function testStreamingHandlesReasoningTextEvents(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.reasoning_text.delta', 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                    [
                        'type' => 'response.output_item.done',
                        'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                    ],
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(10, 15),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $types = array_map(fn (StreamEvent $event): string => $event::class, $events);

        $this->assertContains(ReasoningStart::class, $types);
        $this->assertContains(ReasoningDelta::class, $types);
        $this->assertContains(ReasoningEnd::class, $types);

        $reasoningDelta = collect($events)->first(fn (StreamEvent $event): bool => $event instanceof ReasoningDelta);

        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                        $this->functionCallArgumentsDelta('fc_1', '{}'),
                        $this->functionCallArgumentsDone('fc_1', '{}'),
                        $this->responseCompleted(10, 5, output: [
                            ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                        ]),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        $this->outputTextDelta('The number is 72019'),
                        $this->outputTextDone('The number is 72019'),
                        $this->responseCompleted(20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->resultId);
        $this->assertSame(FinishReason::Stop->value, $streamEnd->reason);
        $this->assertSame(30, $streamEnd->usage->inputTokens);
        $this->assertSame(15, $streamEnd->usage->outputTokens);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('rate_limit_exceeded', $error->type);
        $this->assertSame('Rate limit exceeded', $error->message);
    }

    public function testStreamingCapturesUsageFromCompletedEvent(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDone('Hello'),
                    $this->responseCompleted(42, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(array $output, FinishReason $expected): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->responseCompleted(10, 5, output: $output),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Provide completed response output and its finish reason.
     */
    public static function finishReasons(): array
    {
        return [
            'completed message maps to Stop' => [
                [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
                FinishReason::Stop,
            ],
            'incomplete maps to Length' => [
                [['type' => 'message', 'status' => 'incomplete', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
                FinishReason::Length,
            ],
            'completed function_call maps to ToolCalls' => [
                [['type' => 'function_call', 'status' => 'completed', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']],
                FinishReason::ToolCalls,
            ],
            'failed maps to Error' => [
                [['type' => 'message', 'status' => 'failed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
                FinishReason::Error,
            ],
            'unknown status maps to Unknown' => [
                [['type' => 'message', 'status' => 'mystery_status', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
                FinishReason::Unknown,
            ],
            'completed unknown type maps to Unknown' => [
                [['type' => 'mystery_output', 'status' => 'completed']],
                FinishReason::Unknown,
            ],
        ];
    }
}
