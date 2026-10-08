<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Responses\Data\ToolCall as ToolCallData;
use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Tests\TestCase;

class TextDeltaTest extends TestCase
{
    public function testCombineJoinsTheDeltasOfASingleStepWithoutSeparators(): void
    {
        $events = [
            $this->stepStart(),
            $this->textDelta('message-1', 'Hello'),
            $this->textDelta('message-1', ' there'),
            $this->textDelta('message-1', '!'),
        ];

        $this->assertSame('Hello there!', TextDelta::combine($events));
    }

    public function testCombineSeparatesTheTextOfDifferentStepsWithABlankLine(): void
    {
        $events = [
            $this->stepStart(),
            $this->textDelta('message-1', 'Let me look that up.'),
            new ToolCall(uniqid(), new ToolCallData('call-1', 'get_weather', ['city' => 'Copenhagen']), time()),
            new ToolResult(uniqid(), new ToolResultData('call-1', 'get_weather', ['city' => 'Copenhagen'], '12°C'), true, null, time()),
            $this->stepStart(),
            $this->textDelta('message-2', 'It is '),
            $this->textDelta('message-2', '12°C in Copenhagen.'),
        ];

        $this->assertSame("Let me look that up.\n\nIt is 12°C in Copenhagen.", TextDelta::combine($events));
    }

    public function testCombineKeepsAStepWholeWhenAProviderSplitsItAroundACitation(): void
    {
        // Anthropic opens a new message ID per content block, and web search splits one answer across several of them. A live run returned seven IDs in a single step, breaking mid-sentence; grouping by ID gave each fragment a paragraph of its own, and a lone full stop after...
        $events = [
            $this->stepStart(),
            $this->textDelta('message-1', 'Hypervel 13 is current, which'),
            $this->textDelta('message-2', ' shipped in March'),
            $this->textDelta('message-3', '.'),
        ];

        $this->assertSame('Hypervel 13 is current, which shipped in March.', TextDelta::combine($events));
    }

    public function testCombineDropsAStepThatProducedOnlyWhitespace(): void
    {
        $events = [
            $this->stepStart(),
            $this->textDelta('message-1', 'First.'),
            $this->stepStart(),
            $this->textDelta('message-2', "\n"),
            $this->stepStart(),
            $this->textDelta('message-3', 'Second.'),
        ];

        $this->assertSame("First.\n\nSecond.", TextDelta::combine($events));
    }

    public function testCombineIgnoresEventsThatAreNotTextDeltas(): void
    {
        $events = [
            $this->stepStart(),
            new TextStart(uniqid(), 'message-1', time()),
            $this->textDelta('message-1', 'Only text.'),
            new TextEnd(uniqid(), 'message-1', time()),
        ];

        $this->assertSame('Only text.', TextDelta::combine($events));
    }

    public function testCombineJoinsDeltasThatArriveBeforeAnyStepStart(): void
    {
        // A stream that never announced a step still has to yield its text rather than drop it...
        $events = [
            $this->textDelta('message-1', 'Hello'),
            $this->textDelta('message-2', ' there!'),
        ];

        $this->assertSame('Hello there!', TextDelta::combine($events));
    }

    public function testCombineReturnsAnEmptyStringWhenThereAreNoTextDeltas(): void
    {
        $this->assertSame('', TextDelta::combine([]));
    }

    /**
     * Create a text delta event.
     */
    protected function textDelta(string $messageId, string $delta): TextDelta
    {
        return new TextDelta(uniqid(), $messageId, $delta, time());
    }

    /**
     * Build the stream start that every gateway yields exactly once at the top of a step.
     */
    protected function stepStart(): StreamStart
    {
        return new StreamStart(uniqid(), 'fake', 'fake-model', time());
    }
}
