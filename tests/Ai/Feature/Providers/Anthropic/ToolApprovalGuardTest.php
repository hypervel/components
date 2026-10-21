<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Exceptions\ApprovalNotResumableException;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Vercel\Vercel;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StatelessApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StatelessMixedToolsAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\SideEffectRecorder;
use Hypervel\Tests\Ai\TestCase;

class ToolApprovalGuardTest extends TestCase
{
    public function testAGatedToolOnANonConversationalAgentThrowsWhenItPauses(): void
    {
        $this->expectException(ApprovalNotResumableException::class);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        (new StatelessApprovableAgent)->prompt('Generate a number', provider: 'anthropic');
    }

    public function testAGatedToolOnANonConversationalAgentThrowsBeforeStreamingAPauseToTheClient(): void
    {
        StatelessApprovableAgent::fake([
            new ToolCall('toolu_1', 'ApprovableNumberGenerator', [], 'result-1'),
        ]);

        $stream = (new StatelessApprovableAgent)->stream('Generate a number');

        $events = [];
        $thrown = null;

        try {
            foreach ($stream as $event) {
                $events[] = $event;
            }
        } catch (ApprovalNotResumableException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ApprovalNotResumableException::class, $thrown);
        $this->assertEmpty(array_filter($events, fn ($event): bool => $event instanceof ToolApprovalRequest));
    }

    public function testAGatedToolPausesInsteadOfThrowingWhenTheClientReplaysHistoryEvenOnTheFirstTurn(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $paused = (new StatelessApprovableAgent)
            ->withMessages([])
            ->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertSame('toolu_1', $paused->pendingApprovals[0]->id);
    }

    public function testAStatelessPauseResumesFromClientReplayedHistoryWhenApproved(): void
    {
        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Generate a number']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-ApprovableNumberGenerator', 'toolCallId' => 'toolu_1', 'state' => 'approval-responded', 'input' => [], 'approval' => ['id' => 'toolu_1', 'approved' => true]],
            ]],
        ]);

        $response = (new StatelessApprovableAgent)
            ->withMessages($chat->history())
            ->prompt($chat, provider: 'anthropic');

        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
        $this->assertSame('The number is 72019.', $response->text);
    }

    public function testAGatedToolOnAConversationalAgentPausesOwnerlessInsteadOfThrowing(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $paused = (new RememberingApprovableAgent)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertNotNull($paused->conversationId);
        $this->assertNull($paused->conversationUser);
    }

    public function testANonResumablePauseRunsItsStepThenThrows(): void
    {
        SideEffectRecorder::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_side',
                        'name' => 'SideEffectRecorder',
                        'input' => (object) [],
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_gated',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        // The pause is rejected because the agent cannot resume; its ungated companion runs first, as any step's would.
        try {
            (new StatelessMixedToolsAgent)->prompt('Record and generate', provider: 'anthropic');
            $this->fail('Expected a non-resumable approval pause.');
        } catch (ApprovalNotResumableException) {
            $this->assertSame(1, SideEffectRecorder::$invocations);
        }
    }
}
