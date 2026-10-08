<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Concerns;

use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;

class JoinsReasoningTest extends TestCase
{
    use JoinsReasoning;

    public function testItSeparatesEachBlockWithABlankLine(): void
    {
        $this->assertSame("First.\n\nSecond.", self::joinReasoning(['First.', 'Second.']));
    }

    public function testItDropsBlocksThatHoldNoText(): void
    {
        $this->assertSame("First.\n\nSecond.", self::joinReasoning(['First.', '', '   ', "\n", 'Second.']));
    }

    public function testItJoinsACollectionTheSameWayItJoinsAnArray(): void
    {
        $this->assertSame("First.\n\nSecond.", self::joinReasoning(new Collection(['First.', 'Second.'])));
    }

    public function testCombiningReasoningDeltasAppliesTheSameRule(): void
    {
        $events = [
            new ReasoningDelta('e1', 'rs_1', 'Let me ', 0),
            new ReasoningDelta('e2', 'rs_1', 'think...', 0),
            new ReasoningDelta('e3', 'rs_2', '   ', 0),
            new ReasoningDelta('e4', 'rs_3', 'Now I am sure.', 0),
        ];

        $this->assertSame("Let me think...\n\nNow I am sure.", ReasoningDelta::combine($events));
    }
}
