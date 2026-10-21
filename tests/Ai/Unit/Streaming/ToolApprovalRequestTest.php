<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Tests\TestCase;

class ToolApprovalRequestTest extends TestCase
{
    public function testTheReplayStateNeverReachesASerializedEvent(): void
    {
        $event = new ToolApprovalRequest('event-id', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collect([$this->pausedStep()]));

        $this->assertArrayNotHasKey('steps', $event->toArray());
        $this->assertSame('tool_approval_request', $event->toArray()['type']);
    }

    /**
     * Create a paused step containing private replay state.
     */
    protected function pausedStep(): Step
    {
        return new Step('', [], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]);
    }
}
