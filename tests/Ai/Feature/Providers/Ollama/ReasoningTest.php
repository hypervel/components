<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;

class ReasoningTest extends TestCase
{
    use OllamaHelpers;

    public function testPromptReadsTheThinkingOffTheResponse(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'deepseek-r1:8b',
            'message' => [
                'role' => 'assistant',
                'thinking' => 'Let me think...',
                'content' => 'Hello',
            ],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'ollama');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    public function testStreamingEmitsReasoningEventsBeforeTheText(): void
    {
        Http::fake(['*' => Http::response($this->ndjsonPayload([
            ['model' => 'deepseek-r1:8b', 'message' => ['role' => 'assistant', 'thinking' => 'Let me '], 'done' => false],
            ['model' => 'deepseek-r1:8b', 'message' => ['role' => 'assistant', 'thinking' => 'think...'], 'done' => false],
            $this->chatChunk('Hello'),
            $this->chatChunk('', done: true, doneReason: 'stop', usage: ['prompt_eval_count' => 1, 'eval_count' => 1]),
        ]))]);

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
