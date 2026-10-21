<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;

class ReasoningTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testPromptReadsTheThinkingChunksOffTheResponse(): void
    {
        Http::fake(['*' => $this->fakeTextResponse([
            ['type' => 'thinking', 'thinking' => [
                ['type' => 'text', 'text' => 'Let me '],
                ['type' => 'text', 'text' => 'think...'],
            ]],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'mistral');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    public function testPromptSeparatesEachThinkingChunkWithABlankLine(): void
    {
        Http::fake(['*' => $this->fakeTextResponse([
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'First.']]],
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => '   ']]],
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Second.']]],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $this->assertSame("First.\n\nSecond.", (new AssistantAgent)->prompt('Hi', provider: 'mistral')->reasoning);
    }

    public function testStreamingEmitsReasoningEventsBeforeTheText(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me ']]]]], 'finish_reason' => null]]],
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'think...']]]]], 'finish_reason' => null]]],
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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

    public function testADeltaCarryingBothTheLastThinkingAndTheFirstTextClosesOneReasoningBlock(): void
    {
        Http::fake(['*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Let me ']]]]], 'finish_reason' => null]]],
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'think...']]],
                    ['type' => 'text', 'text' => 'Hello'],
                ]], 'finish_reason' => null]]],
                ['id' => 'c1', 'model' => 'magistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $events = $this->collectStreamEvents();

        $this->assertCount(1, collect($events)->filter(fn (StreamEvent $event): bool => $event instanceof ReasoningStart));
        $this->assertInstanceOf(ReasoningStart::class, $events[1]);
        $this->assertInstanceOf(ReasoningDelta::class, $events[2]);
        $this->assertSame('Let me ', $events[2]->delta);
        $this->assertInstanceOf(ReasoningDelta::class, $events[3]);
        $this->assertSame('think...', $events[3]->delta);
        $this->assertInstanceOf(ReasoningEnd::class, $events[4]);
        $this->assertInstanceOf(TextStart::class, $events[5]);
        $this->assertSame('Let me think...', ReasoningDelta::combine($events));
    }
}
