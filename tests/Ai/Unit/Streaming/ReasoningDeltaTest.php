<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Tests\TestCase;

class ReasoningDeltaTest extends TestCase
{
    public function testCombineJoinsDeltasOfASingleReasoningBlockWithoutSeparators(): void
    {
        $events = [
            $this->reasoningDelta('reasoning-1', 'The user'),
            $this->reasoningDelta('reasoning-1', ' wants'),
            $this->reasoningDelta('reasoning-1', ' the weather.'),
        ];

        $this->assertSame('The user wants the weather.', ReasoningDelta::combine($events));
    }

    public function testCombineSeparatesReasoningFromDifferentStepsWithABlankLine(): void
    {
        $events = [
            $this->reasoningDelta('reasoning-1', 'I should look this up.'),
            $this->reasoningDelta('reasoning-2', 'The tool answered, so I can reply.'),
        ];

        $this->assertSame("I should look this up.\n\nThe tool answered, so I can reply.", ReasoningDelta::combine($events));
    }

    public function testCombineDropsWhitespaceOnlyReasoningBlocks(): void
    {
        $events = [
            $this->reasoningDelta('reasoning-1', 'First.'),
            $this->reasoningDelta('reasoning-2', "\n"),
            $this->reasoningDelta('reasoning-3', 'Second.'),
        ];

        $this->assertSame("First.\n\nSecond.", ReasoningDelta::combine($events));
    }

    public function testCombineIgnoresEventsThatAreNotReasoningDeltas(): void
    {
        $events = [
            new StreamStart(uniqid(), 'fake', 'fake-model', time()),
            new TextDelta(uniqid(), 'message-1', 'The answer.', time()),
            $this->reasoningDelta('reasoning-1', 'Only reasoning.'),
        ];

        $this->assertSame('Only reasoning.', ReasoningDelta::combine($events));
    }

    public function testCombineReturnsAnEmptyStringWhenThereAreNoReasoningDeltas(): void
    {
        $this->assertSame('', ReasoningDelta::combine([]));
    }

    /**
     * Create a reasoning delta event.
     */
    protected function reasoningDelta(string $reasoningId, string $delta): ReasoningDelta
    {
        return new ReasoningDelta(uniqid(), $reasoningId, $delta, time());
    }
}
