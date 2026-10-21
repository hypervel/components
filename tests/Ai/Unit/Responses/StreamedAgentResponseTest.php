<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall as ToolCallData;
use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Tests\TestCase;

class StreamedAgentResponseTest extends TestCase
{
    public function testAPausedStreamExposesTheStepsCarriedByTheApprovalRequest(): void
    {
        $response = $this->streamedResponseFor([
            new ToolApprovalRequest('e1', collect([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collect([$this->streamedStep('')])),
        ]);

        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-1']], $response->steps->first()->replayBlocks);
    }

    public function testACompletedStreamExposesTheStepsCarriedByTheStreamEnd(): void
    {
        $response = $this->streamedResponseFor([new StreamEnd('e1', 'stop', new TextUsage, 1, collect([$this->streamedStep('Done.')]))]);

        $this->assertCount(1, $response->steps);
        $this->assertSame('Done.', $response->steps->first()->text);
        $this->assertSame('I thought about it.', $response->steps->first()->reasoning);
    }

    public function testAStreamCarryingNeitherEventExposesNoSteps(): void
    {
        $this->assertEmpty($this->streamedResponseFor([])->steps);
    }

    public function testAggregationPreservesStepTextReasoningAndUsage(): void
    {
        $events = collect([
            new StreamStart('start-1', 'openai', 'model', 1),
            new TextDelta('text-1', 'block-1', 'Hello ', 1),
            new ReasoningDelta('reasoning-1', 'first', 'First ', 1),
            new TextDelta('text-2', 'block-2', 'world.', 1),
            new ReasoningDelta('reasoning-2', 'second', 'Second block.', 1),
            new ReasoningDelta('reasoning-3', 'first', 'block.', 1),
            new StreamEnd('end-1', 'tool_calls', new TextUsage(10, 4, 3, null, 1), 1),
            new StreamStart('start-2', 'openai', 'model', 2),
            new TextDelta('text-3', 'blank', " \n", 2),
            new ReasoningDelta('reasoning-4', 'blank', ' ', 2),
            new StreamStart('start-3', 'openai', 'model', 3),
            new TextDelta('text-4', 'block-3', 'Done.', 3),
            new StreamEnd('end-3', 'stop', new TextUsage(8, 2, null, null, 0), 3),
        ]);

        $response = new StreamedAgentResponse('invocation-id', $events, new Meta);

        $this->assertSame("Hello world.\n\nDone.", $response->text);
        $this->assertSame("First block.\n\nSecond block.", $response->reasoning);
        $this->assertEquals(new TextUsage(18, 6, 3, null, 1), $response->usage);
        $this->assertSame($events, $response->events);
    }

    public function testAggregationRetainsSettledToolsCitationsAndTheLatestSteps(): void
    {
        $call = new ToolCallData('call-1', 'research', []);
        $result = new ToolResultData('call-1', 'research', [], 'Done.');
        $citation = new UrlCitation('https://example.com');
        $approval = new PendingApproval('call-2', 'DeleteFile', [], 'Deletes a file');
        $steps = collect([$this->streamedStep('Done.')]);
        $response = $this->streamedResponseFor([
            new ToolCall('call', $call, 1),
            new ToolCall('structured', new ToolCallData('structured', 'output_structured_data', []), 1),
            new ToolResult('progress', new ToolResultData('call-1', 'research', [], 'Working...'), true, null, 1, preliminary: true),
            new ToolResult('result', $result, true, null, 1),
            new Citation('citation-1', 'message', $citation, 1),
            new Citation('citation-2', 'message', $citation, 1),
            new StreamEnd('end', 'tool_calls', new TextUsage, 1, collect([$this->streamedStep('Earlier.')])),
            new ToolApprovalRequest('approval', collect([$approval]), 2, $steps),
        ]);

        $this->assertSame([$call], $response->toolCalls->all());
        $this->assertSame([$result], $response->toolResults->all());
        $this->assertSame([$citation, $citation], $response->meta->citations->all());
        $this->assertSame([$approval], $response->pendingApprovals->all());
        $this->assertSame($steps, $response->steps);
    }

    /**
     * Create a response from recorded events.
     */
    protected function streamedResponseFor(array $events): StreamedAgentResponse
    {
        return new StreamedAgentResponse('invocation-id', collect($events), new Meta);
    }

    /**
     * Create a step containing reasoning and replay state.
     */
    protected function streamedStep(string $text): Step
    {
        return new Step($text, [], [], FinishReason::Stop, new TextUsage, new Meta, 'I thought about it.', [['type' => 'thinking', 'signature' => 'sig-1']]);
    }
}
