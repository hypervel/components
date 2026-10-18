<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Tests\TestCase;

class ScoreAnswerTest extends TestCase
{
    public function testScoreAnswerReportsTheMostProbableLevel(): void
    {
        $answer = new ScoreAnswer(1.99, [0 => 0.0, 1 => 0.01, 2 => 0.99], [0 => 'Low', 1 => 'Medium', 2 => 'High']);

        $this->assertSame(2, $answer->level());
        $this->assertSame('High', $answer->label());
        $this->assertSame(0.995, $answer->normalized());
    }

    public function testScoreAnswerRoundsTheScoreWhenNoDistributionWasReported(): void
    {
        $answer = new ScoreAnswer(0.4, [], [0 => 'Low', 1 => 'Medium', 2 => 'High']);

        $this->assertSame(0, $answer->level());
        $this->assertSame('Low', $answer->label());
    }
}
