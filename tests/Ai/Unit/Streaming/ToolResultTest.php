<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Tests\TestCase;

class ToolResultTest extends TestCase
{
    public function testAPreliminaryResultFlagsItselfWhileKeepingTheIdentityOfTheToolCallItBelongsTo(): void
    {
        $event = (new ToolResult(
            'event-1',
            new ToolResultData('call-1', 'research_agent', ['task' => 'Research'], 'Working...'),
            true,
            null,
            100,
            preliminary: true,
        ))->withInvocationId('parent-invocation');

        $this->assertSame([
            'id' => 'event-1',
            'invocation_id' => 'parent-invocation',
            'type' => 'tool_result',
            'tool_id' => 'call-1',
            'tool_name' => 'research_agent',
            'result' => 'Working...',
            'successful' => true,
            'error' => null,
            'denied' => false,
            'preliminary' => true,
            'timestamp' => 100,
        ], $event->toArray());
    }

    public function testASettledResultSaysNothingAboutBeingPreliminary(): void
    {
        $event = new ToolResult(
            'event-1',
            new ToolResultData('call-1', 'research_agent', [], 'done'),
            true,
            null,
            100,
        );

        $this->assertArrayNotHasKey('preliminary', $event->toArray());
    }
}
