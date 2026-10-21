<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Events\ToolApprovalRequested;
use Hypervel\Ai\Events\ToolApprovalResolved;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\QueuedAgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Ai\Tools\Request as ToolRequest;
use Hypervel\Broadcasting\Channel;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ConversationalAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ToolApprovalTest extends TestCase
{
    use RefreshDatabase;
    use AnthropicHelpers;

    public function testAnApprovalMismatchRendersAsA409CarryingThePendingApprovals(): void
    {
        $exception = new ApprovalMismatchException('Approval decisions do not match the pending tool calls.', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a tracked project file'),
        ]));

        $response = $exception->render(new Request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'message' => 'Approval decisions do not match the pending tool calls.',
            'approvals' => [[
                'id' => 'call-1',
                'tool' => 'DeleteFile',
                'arguments' => ['path' => 'config/app.php'],
                'reason' => 'Deletes a tracked project file',
            ]],
        ], json_decode($response->getContent(), true));
    }

    public function testDecisionMapsNormalizeBooleansBlankReasonsAndBlanketHelpers(): void
    {
        $approval = Decision::normalize([
            'call-1' => true,
            'call-2' => false,
            'call-3' => Decision::edit(['path' => '/tmp/file']),
        ]);

        $approveAll = Decision::approveAll();
        $rejectAll = Decision::rejectAll();

        $this->assertTrue($approval['call-1']->isApproved());
        $this->assertTrue($approval['call-2']->isRejected());
        $this->assertTrue($approval['call-3']->isEdited());
        $this->assertSame(['path' => '/tmp/file'], $approval['call-3']->arguments);
        $this->assertNull(Decision::reject('')->result);
        $this->assertNull(Decision::reject('   ')->result);
        $this->assertSame('Already handled', Decision::reject('Already handled')->result);
        $this->assertTrue($approveAll->get('*')->isApproved());
        $this->assertTrue($rejectAll->get('*')->isRejected());
        $this->assertNull($rejectAll->get('*')->result);
    }

    public function testDecisionMapsMayApproveOrRejectEveryRemainingToolCall(): void
    {
        $approved = Decisions::from(['call-1' => false])->approveRemaining();
        $rejected = Decisions::from(['call-1' => true])->rejectRemaining('Not now');

        $this->assertTrue($approved->get('call-1')->isRejected());
        $this->assertTrue($approved->get('*')->isApproved());
        $this->assertTrue($rejected->get('call-1')->isApproved());
        $this->assertTrue($rejected->get('*')->isRejected());
        $this->assertSame('Not now', $rejected->get('*')->result);
    }

    #[DataProvider('invalidDecisionMaps')]
    public function testInvalidDecisionMapsAreRejected(Closure $callback, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $callback();
    }

    /**
     * Provide invalid decision maps and their errors.
     */
    public static function invalidDecisionMaps(): array
    {
        return [
            'wildcard edit' => [fn (): array => Decision::normalize(['*' => Decision::edit(['path' => '/tmp/file'])]), 'The wildcard decision may not use the edit action.'],
            'string decision' => [fn (): array => Decision::normalize(['call-1' => 'approve']), 'Tool approval decisions must be Decision instances or booleans.'],
            'nested map' => [fn (): array => Decision::normalize(['call-1' => ['call-1' => false]]), 'Tool approval decisions must be Decision instances or booleans.'],
            'empty decisions' => [fn (): AgentResponse => (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt(Decisions::from([])), 'Tool approval decisions may not be empty.'],
        ];
    }

    public function testAPausedResponseExposesEverythingAControllerNeedsToBuildItsOwnApprovalEnvelope(): void
    {
        $pending = new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file');

        $paused = AgentResponse::fakeWithPendingApprovals([$pending])->withinConversation('conversation-1');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertSame('conversation-1', $paused->conversationId);
        $this->assertSame([$pending->toArray()], $paused->pendingApprovals->toArray());
    }

    public function testNonPausedResponsesRenderWithoutTheApprovalEnvelope(): void
    {
        $complete = (new AgentResponse('invocation-1', 'Done', new TextUsage, new Meta))->withinConversation('conversation-1');
        $structured = new StructuredAgentResponse('invocation-1', ['number' => 72019], '72019', new TextUsage, new Meta);

        $this->assertFalse($complete->hasPendingApprovals());
        $this->assertSame('conversation-1', $complete->conversationId);
        $this->assertSame('Done', $complete->text);
        $this->assertNotInstanceOf(Responsable::class, $complete);
        $this->assertSame('Done', (string) $complete);
        $this->assertSame(json_encode(['number' => 72019]), $structured->toJson());
        $this->assertSame(['number' => 72019], $structured->toArray());
    }

    public function testApprovalOverridesTakePrecedenceOverTheToolDefault(): void
    {
        $tool = new ApprovableNumberGenerator;

        $this->assertNotNull($tool->shouldRequestApproval(new ToolRequest([])));
        $this->assertNull($tool->withoutApproval()->shouldRequestApproval(new ToolRequest([])));
        $this->assertNotNull($tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([])));
        $this->assertSame('Dangerous', $tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([]))->reason);
    }

    public function testAPausedPromptDispatchesTheToolApprovalRequestedEventWithItsConversation(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Event::fake([ToolApprovalRequested::class]);

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

        $paused = (new RememberingApprovableAgent)
            ->forUser((object) ['id' => 1])
            ->prompt('Generate a number', provider: 'anthropic');

        Event::assertDispatched(ToolApprovalRequested::class, function (ToolApprovalRequested $event) use ($paused): bool {
            return $event->pendingApprovals->count() === 1
                && $event->pendingApprovals[0]->id === 'toolu_1'
                && $event->conversationId === $paused->conversationId
                && $event->conversationId !== null;
        });
    }

    public function testAPausedStreamDispatchesTheToolApprovalRequestedEvent(): void
    {
        Event::fake([ToolApprovalRequested::class]);

        ConversationalAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]),
        ]);

        $response = (new ConversationalAgent)->stream('Delete config/app.php');

        $response->each(fn (): bool => true);

        Event::assertDispatched(ToolApprovalRequested::class, function (ToolApprovalRequested $event): bool {
            return $event->pendingApprovals->count() === 1
                && $event->pendingApprovals[0]->id === 'call-1';
        });
    }

    public function testApprovalResumesFlowThroughQueueAndBroadcastDeliveryStyles(): void
    {
        ConversationalAgent::fake();

        (new ConversationalAgent)->queue(Decisions::from(['call-1' => true]));

        ConversationalAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });

        (new ConversationalAgent)
            ->broadcast(Decisions::from(['call-1' => true]), new Channel('approvals'))
            ->each(fn (): bool => true);

        ConversationalAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });

        (new ConversationalAgent)->broadcastOnQueue(Decisions::from(['call-1' => true]), new Channel('approvals'));

        ConversationalAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });

        (new ConversationalAgent)->queue(Decision::approveAll());

        ConversationalAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('*')?->isApproved() === true;
        });
    }

    public function testARunThatOnlyPausesDoesNotDispatchTheToolApprovalResolvedEvent(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Event::fake([ToolApprovalResolved::class]);

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

        (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'anthropic');

        Event::assertNotDispatched(ToolApprovalResolved::class);
    }

    public function testAMixedResumeDispatchesTheToolApprovalResolvedEventWithApprovedAndDeniedResults(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Event::fake([ToolApprovalResolved::class]);

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
                ], [
                    'type' => 'tool_use',
                    'id' => 'toolu_2',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true, 'toolu_2' => false]), provider: 'anthropic');

        Event::assertDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event): bool {
            return $event->toolResults->count() === 2
                && $event->toolResults[0]->id === 'toolu_1'
                && $event->toolResults[0]->denied === false
                && $event->toolResults[1]->id === 'toolu_2'
                && $event->toolResults[1]->denied === true
                && $event->conversationId !== null;
        });
    }

    public function testAnApprovedStreamedResumeDispatchesTheToolApprovalResolvedEvent(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Event::fake([ToolApprovalResolved::class]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
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
                Http::response($this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'The number is 72019.']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 5),
                ]), 200, ['Content-Type' => 'text/event-stream']),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $response = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $response->each(fn (): bool => true);

        $this->assertSame('The number is 72019.', $response->text);

        Event::assertDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event): bool {
            return $event->toolResults->count() === 1
                && $event->toolResults[0]->id === 'toolu_1'
                && $event->toolResults[0]->denied === false;
        });
    }
}
