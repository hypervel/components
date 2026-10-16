<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

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
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hello', ' world'))]);

        $events = $this->collectStreamEvents();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(TextStart::class, $events[1]);
        $this->assertInstanceOf(TextDelta::class, $events[2]);
        $this->assertSame('Hello', $events[2]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[3]);
        $this->assertSame(' world', $events[3]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[4]);
        $this->assertInstanceOf(StreamEnd::class, $events[5]);
        $this->assertCount(6, $events);
    }

    public function testStreamingCapturesUsageAndFinishReason(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

        $streamEnd = collect($this->collectStreamEvents())->last();

        $this->assertInstanceOf(StreamEnd::class, $streamEnd);
        $this->assertSame(FinishReason::Stop->value, $streamEnd->reason);
        $this->assertSame(20, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    public function testStreamingAccumulatesToolCallArgumentsAndRunsTheTool(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeStreamResponse($this->streamToolCallEvents('FixedNumberGenerator', 'get_number_ejj5xe67w3e1', ['{"', 'city', '":', ' "', 'Paris', '"}'])),
                $this->fakeStreamResponse($this->streamTextEvents('The number is 72019')),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCalls = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
        $toolResults = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));
        $streamEnds = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd));

        $this->assertCount(1, $toolCalls);
        $this->assertSame('get_number_ejj5xe67w3e1', $toolCalls[0]->toolCall->id);
        $this->assertSame('FixedNumberGenerator', $toolCalls[0]->toolCall->name);
        $this->assertSame(['city' => 'Paris'], $toolCalls[0]->toolCall->arguments);
        $this->assertCount(1, $toolResults);
        $this->assertCount(1, $streamEnds);
        $this->assertSame(FinishReason::Stop->value, $streamEnds[0]->reason);
        $this->assertSame(30, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);

        $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

        $this->assertSame('get_number_ejj5xe67w3e1', collect($followUp['messages'])->firstWhere('role', 'tool')['tool_call_id']);
    }

    public function testStreamingIgnoresToolPlanDeltas(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeStreamResponse($this->streamToolCallEvents()),
                $this->fakeStreamResponse($this->streamTextEvents('Done')),
            ]),
        ]);

        $deltas = collect($this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent))
            ->filter(fn (StreamEvent $event): bool => $event instanceof TextDelta)
            ->map(fn (TextDelta $event): string => $event->delta)
            ->values()
            ->all();

        $this->assertSame(['Done'], $deltas);
    }

    public function testStreamingEmitsReasoningEventsBeforeTheText(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse([
            ['type' => 'message-start', 'id' => 'msg_1', 'delta' => ['message' => ['role' => 'assistant']]],
            ['type' => 'content-start', 'index' => 0, 'delta' => ['message' => ['content' => ['type' => 'thinking', 'thinking' => '']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'Let me ']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'think...']]]],
            ['type' => 'content-end', 'index' => 0],
            ['type' => 'content-start', 'index' => 1, 'delta' => ['message' => ['content' => ['type' => 'text', 'text' => '']]]],
            ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Hello']]]],
            ['type' => 'content-end', 'index' => 1],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 5]]]],
        ])]);

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
    }

    public function testStreamingExposesTheCombinedReasoningOnTheResponse(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse([
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'Let me ']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'think...']]]],
            ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Hello']]]],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 5]]]],
        ])]);

        $stream = (new AssistantAgent)->stream('Hello', provider: 'cohere');

        iterator_to_array($stream);

        $this->assertSame('Let me think...', $stream->reasoning);
        $this->assertSame('Hello', $stream->text);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse([
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Hel']]]],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'TIMEOUT', 'error' => 'Generation timed out']],
        ])]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('timeout', $error->type);
        $this->assertSame('Generation timed out', $error->message);
    }

    #[DataProvider('unfinishedStreams')]
    public function testStreamingRejectsPrematureEof(bool $toolCall): void
    {
        $events = $toolCall
            ? array_slice($this->streamToolCallEvents(argumentChunks: ['{"city":']), 0, -2)
            : array_slice($this->streamTextEvents('Partial'), 0, -2);

        Http::fake(['*' => Http::sequence([
            $this->fakeStreamResponse($events),
            $this->fakeStreamResponse($this->streamTextEvents('Done')),
        ])]);

        $error = null;
        $toolCalls = [];

        try {
            foreach ((new ProviderOptionsWithToolsAgent)->stream('Hello', provider: 'cohere') as $event) {
                if ($event instanceof ToolCallEvent) {
                    $toolCalls[] = $event;
                }
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);

        $this->assertSame([], $toolCalls);
    }

    /**
     * Provide text and tool-call streams that end before message-end.
     */
    public static function unfinishedStreams(): array
    {
        return ['partial text' => [false], 'unfinished tool call' => [true]];
    }
}
