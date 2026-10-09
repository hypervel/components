<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway;

use Closure;
use Generator;
use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Attributes\RepairToolCalls;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Contracts\ClaimsPendingApprovals;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

class TextGenerationLoopTest extends TestCase
{
    public function testItPausesGatedToolCallsWithoutExecutingThemWhileRunningUngatedCallsImmediately(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $ungated = new TextGenerationLoopCountingTool;
        $gatedCall = new ToolCall('call-gated', 'TextGenerationLoopApprovableTool', ['value' => 'danger'], 'call-gated');
        $ungatedCall = new ToolCall('call-ungated', 'TextGenerationLoopCountingTool', [], 'call-ungated');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse(
                text: '',
                toolCalls: [$gatedCall, $ungatedCall],
                finishReason: FinishReason::ToolCalls,
                usage: new TextUsage,
                meta: new Meta('fake', 'model'),
            ),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$gated, $ungated],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        );

        $this->assertSame(0, $gated->calls);
        $this->assertSame(1, $ungated->calls);
        $this->assertSame(1, $gateway->generateCalls);
        $this->assertTrue($response->hasPendingApprovals());
        $this->assertCount(1, $response->pendingApprovals);
        $this->assertSame('call-gated', $response->pendingApprovals[0]->id);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('call-ungated', $response->toolResults[0]->id);
        $this->assertSame('counted', $response->toolResults[0]->result);
        $this->assertSame('call-gated', $gated->approvalToolCallId);
    }

    public function testItResumesAMixedBatchOfApprovedEditedAndRejectedCalls(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $approvedCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $editedCall = new ToolCall('call-2', 'TextGenerationLoopApprovableTool', ['value' => 'original'], 'call-2');
        $rejectedCall = new ToolCall('call-3', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-3');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse(
                text: 'done',
                toolCalls: [],
                finishReason: FinishReason::Stop,
                usage: new TextUsage,
                meta: new Meta('fake', 'model'),
            ),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$approvedCall, $editedCall, $rejectedCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            [
                'call-1' => Decision::approve(),
                'call-2' => Decision::edit(['value' => 'edited']),
                'call-3' => Decision::reject('Not that file'),
            ],
        );

        $this->assertSame(2, $tool->calls);
        $this->assertSame([['value' => 'approved'], ['value' => 'edited']], $tool->handledArguments);
        $this->assertSame(1, $gateway->generateCalls);
        $this->assertFalse($response->hasPendingApprovals());
        $this->assertCount(3, $response->toolResults);
        $this->assertSame('handled approved', $response->toolResults->firstWhere('id', 'call-1')->result);
        $this->assertSame('handled edited', $response->toolResults->firstWhere('id', 'call-2')->result);
        $this->assertSame('Not that file', $response->toolResults->firstWhere('id', 'call-3')->result);
        $this->assertSame('done', $response->text);
    }

    public function testMismatchedOrStaleApprovalDecisionsAreRejected(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $pendingCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'pending'], 'call-1');

        // A decision naming an id that is not pending does not match.
        try {
            (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
                textGenerationLoopProvider(),
                'model',
                null,
                [new AssistantMessage('', collect([$pendingCall]))],
                [$tool],
                null,
                new TextGenerationOptions(maxSteps: 2),
                null,
                ['call-2' => Decision::approve()],
            );
            $this->fail('Unknown decisions must be rejected.');
        } catch (ApprovalMismatchException $exception) {
            $this->assertSame('Approval decisions do not match the pending tool calls.', $exception->getMessage());
        }

        $settledCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');

        // An approval arriving after the gated call already has a result is stale.
        $this->expectException(ApprovalMismatchException::class);
        $this->expectExceptionMessage('There are no tool calls pending approval.');
        (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [
                new AssistantMessage('', collect([$settledCall])),
                new ToolResultMessage(collect([
                    new ToolResultData('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'handled approved', 'call-1'),
                ])),
            ],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['*' => Decision::approve()],
        );
    }

    public function testItEmitsStreamedApprovalRequestsWithoutExecutingGatedTools(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'danger'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
                returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        ));

        $approvalEvents = collect($events)->whereInstanceOf(ToolApprovalRequest::class);

        $this->assertSame(0, $tool->calls);
        $this->assertCount(1, $approvalEvents);
        $this->assertSame('call-1', $approvalEvents->first()->pendingApprovals[0]->id);
        $this->assertCount(0, collect($events)->whereInstanceOf(ToolResultEvent::class));
        $this->assertCount(1, collect($events)->whereInstanceOf(StreamEnd::class));
    }

    public function testItResumesAPausedStepRunningOnlyTheStillPendingGatedCall(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $ungated = new TextGenerationLoopCountingTool;
        $gatedCall = new ToolCall('call-gated', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-gated');
        $ungatedCall = new ToolCall('call-ungated', 'TextGenerationLoopCountingTool', [], 'call-ungated');

        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [
                new AssistantMessage('', collect([$gatedCall, $ungatedCall])),
                new ToolResultMessage(collect([
                    new ToolResultData('call-ungated', 'TextGenerationLoopCountingTool', [], 'counted', 'call-ungated'),
                ])),
            ],
            [$gated, $ungated],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-gated' => Decision::approve()],
        );

        $this->assertSame(1, $gated->calls);
        $this->assertSame(0, $ungated->calls);
        $this->assertFalse($response->hasPendingApprovals());
        $this->assertSame(['call-ungated', 'call-gated'], $response->toolResults->pluck('id')->all());
        $this->assertSame('done', $response->text);
    }

    public function testABareRejectionStopsTheLoopWithoutAnotherModelCall(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway;

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-1' => Decision::reject()],
        );

        $this->assertSame(0, $tool->calls);
        $this->assertSame(0, $gateway->generateCalls);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('The user rejected this tool call.', $response->toolResults[0]->result);
    }

    public function testAGatedToolWithApprovalDisabledExecutesWithoutPausing(): void
    {
        $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'safe'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        );

        $this->assertSame(1, $tool->calls);
        $this->assertFalse($response->hasPendingApprovals());
        $this->assertSame('done', $response->text);
    }

    public function testABareRejectionBesideAnApprovedCallRecordsTheExecutedResultAndStopsTheLoop(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $firstCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $secondCall = new ToolCall('call-2', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-2');
        $gateway = new TextGenerationLoopFakeGateway;

        $recorded = null;

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$firstCall, $secondCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-1' => Decision::approve(), 'call-2' => Decision::reject()],
            function (array $toolResults) use (&$recorded): void {
                $recorded = $toolResults;
            },
        );

        $this->assertSame(1, $tool->calls);
        $this->assertSame(0, $gateway->generateCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame('handled approved', $response->toolResults[0]->result);
        $this->assertSame('The user rejected this tool call.', $response->toolResults[1]->result);
        $this->assertNotNull($recorded);
        $this->assertSame('handled approved', collect($recorded)->firstWhere('id', 'call-1')->result);
    }

    public function testAStreamedBareRejectionStopsTheLoopEvenBesideAnApprovedCall(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $firstCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $secondCall = new ToolCall('call-2', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-2');
        $gateway = new TextGenerationLoopFakeGateway;

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$firstCall, $secondCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-1' => Decision::approve(), 'call-2' => Decision::reject()],
        ));

        $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class);

        $this->assertSame(1, $tool->calls);
        $this->assertSame(0, $gateway->streamCalls);
        $this->assertCount(2, $toolResults);
        $this->assertTrue($toolResults->firstWhere(fn (ToolResultEvent $event): bool => $event->toolResult->id === 'call-1')->successful);
        $this->assertTrue($toolResults->firstWhere(fn (ToolResultEvent $event): bool => $event->toolResult->id === 'call-2')->denied);
        $this->assertCount(1, collect($events)->whereInstanceOf(StreamEnd::class));
    }

    public function testAGateThatHasRelaxedSinceThePauseCanStillBeResumed(): void
    {
        $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-1' => Decision::approve()],
        );

        $this->assertSame(1, $tool->calls);
        $this->assertFalse($response->hasPendingApprovals());
        $this->assertSame('done', $response->text);
    }

    public function testARelaxedGateWithNoDecisionFailsClosedInsteadOfAutoRunningTheTool(): void
    {
        $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            [],
        );

        $this->assertSame(0, $tool->calls);
        $this->assertSame('The user rejected this tool call.', $response->toolResults->firstWhere('id', 'call-1')->result);
    }

    public function testAResumedMixedBatchMergesItsResultsIntoThePauseTurnAnsweringMessage(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $ungated = new TextGenerationLoopCountingTool;
        $gatedCall = new ToolCall('call-gated', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-gated');
        $ungatedCall = new ToolCall('call-ungated', 'TextGenerationLoopCountingTool', [], 'call-ungated');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [
                new AssistantMessage('', collect([$ungatedCall, $gatedCall])),
                new ToolResultMessage(collect([
                    new ToolResultData('call-ungated', 'TextGenerationLoopCountingTool', [], 'counted', 'call-ungated'),
                ])),
            ],
            [$gated, $ungated],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-gated' => Decision::approve()],
        );

        $toolResultMessages = $response->messages->whereInstanceOf(ToolResultMessage::class);

        $this->assertSame(1, $gated->calls);
        $this->assertSame(0, $ungated->calls);
        $this->assertCount(1, $toolResultMessages);
        $this->assertCount(2, $toolResultMessages->first()->toolResults);
        $this->assertSame('done', $response->text);
    }

    public function testAPlainGenerationSettlesAbandonedPausesBeforeCallingTheModelEachKeepingItsOwnPlaceholder(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $firstCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'danger'], 'call-1');
        $secondCall = new ToolCall('call-2', 'TextGenerationLoopApprovableTool', ['value' => 'also danger'], 'call-2');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('Sure, moving on.', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [
                new AssistantMessage('', collect([$firstCall])),
                new Message('user', 'Never mind, do something else.'),
                new AssistantMessage('', collect([$secondCall])),
                new Message('user', 'Never mind that either, just say hi.'),
            ],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        );

        $settled = $gateway->messages[0];

        $this->assertSame(0, $tool->calls);
        $this->assertCount(6, $settled);
        $this->assertInstanceOf(ToolResultMessage::class, $settled[1]);
        $this->assertSame('call-1', $settled[1]->toolResults->first()->id);
        $this->assertStringContainsString('not approved', $settled[1]->toolResults->first()->result);
        $this->assertSame('Never mind, do something else.', $settled[2]->content);
        $this->assertInstanceOf(ToolResultMessage::class, $settled[4]);
        $this->assertSame('call-2', $settled[4]->toolResults->first()->id);
        $this->assertStringContainsString('not approved', $settled[4]->toolResults->first()->result);
        $this->assertSame('Never mind that either, just say hi.', $settled[5]->content);
        $this->assertSame('Sure, moving on.', $response->text);
    }

    public function testADefaultDecisionApprovesEveryGatedCallWhileAnExplicitDecisionOverridesIt(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $ungated = new TextGenerationLoopCountingTool;
        $approvedCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $rejectedCall = new ToolCall('call-2', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-2');
        $ungatedCall = new ToolCall('call-ungated', 'TextGenerationLoopCountingTool', [], 'call-ungated');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$approvedCall, $rejectedCall, $ungatedCall]))],
            [$gated, $ungated],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-2' => Decision::reject('Wrong file'), '*' => Decision::approve()],
        );

        $this->assertSame(1, $gated->calls);
        $this->assertSame([['value' => 'approved']], $gated->handledArguments);
        $this->assertSame(0, $ungated->calls);
        $this->assertFalse($response->hasPendingApprovals());
        $this->assertCount(3, $response->toolResults);
        $this->assertSame('Wrong file', $response->toolResults->firstWhere('id', 'call-2')->result);
        $this->assertSame('This tool call was not executed because it was not pending approval.', $response->toolResults->firstWhere('id', 'call-ungated')->result);
        $this->assertSame('done', $response->text);
    }

    public function testADefaultDecisionDoesNotExcuseDecisionsForUnknownToolCalls(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'pending'], 'call-1');

        $this->expectException(ApprovalMismatchException::class);
        (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-2' => Decision::approve(), '*' => Decision::approve()],
        );
    }

    public function testAStreamedDefaultRejectionMarksTheToolResultsAsUnsuccessful(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'blocked'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new TextDelta('text-delta', 'message-1', 'Understood.', time())],
                returns: new StepResponse('Understood.', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['*' => Decision::reject('Not now')],
        ));

        $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class);

        $this->assertSame(0, $tool->calls);
        $this->assertCount(1, $toolResults);
        $this->assertFalse($toolResults->first()->successful);
        $this->assertTrue($toolResults->first()->denied);
        $this->assertSame('Not now', $toolResults->first()->error);
        $this->assertCount(1, collect($events)->whereInstanceOf(StreamEnd::class));
    }

    public function testItDoesNotExecuteToolCallsOnTheFinalGenerationStep(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse(
                text: '',
                toolCalls: [new ToolCall('call-1', 'TextGenerationLoopCountingTool', [], 'call-1')],
                finishReason: FinishReason::ToolCalls,
                usage: new TextUsage,
                meta: new Meta('fake', 'model'),
                continuationToken: 'response-1',
            ),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 1),
            null,
        );

        $this->assertSame(0, $tool->calls);
        $this->assertSame(1, $gateway->generateCalls);
        $this->assertTrue($gateway->contexts[0]->isFinalStep);
        $this->assertCount(1, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('The agent reached its maximum number of steps without running this tool call.', $response->toolResults[0]->result);
        $this->assertFalse($response->toolResults[0]->successful());
        $this->assertSame('The agent reached its maximum number of steps without running this tool call.', $response->toolResults[0]->error());
        $this->assertCount(1, $response->steps);
        $this->assertCount(1, $response->steps->first()->toolResults);
    }

    public function testItPreservesTheExhaustedResultForUnknownToolsWithoutTheRepairAttribute(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [new TextGenerationLoopCountingTool],
            null,
            new TextGenerationOptions(maxSteps: 1),
            null,
        );

        $this->assertSame(1, $gateway->generateCalls);
        $this->assertTrue($gateway->contexts[0]->isFinalStep);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('The agent reached its maximum number of steps without running this tool call.', $response->toolResults[0]->result);
    }

    public function testItHoldsStreamEndUntilTheStreamedToolLoopIsComplete(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $firstToolCall = new ToolCall('call-1', 'TextGenerationLoopCountingTool', [], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event', $firstToolCall, time())],
                returns: new StepResponse(text: '', toolCalls: [$firstToolCall], finishReason: FinishReason::ToolCalls, usage: new TextUsage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
            ),
            textGenerationLoopStreamStep(
                events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
                returns: new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new TextUsage(5, 2), meta: new Meta('fake', 'model'), continuationToken: 'response-2'),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        ));

        $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

        $this->assertSame(1, $tool->calls);
        $this->assertSame(2, $gateway->streamCalls);
        $this->assertCount(1, $streamEnds);
        $this->assertCount(1, collect($events)->whereInstanceOf(ToolResultEvent::class));
        $this->assertSame(FinishReason::Stop->value, $streamEnds->first()->reason);
        $this->assertSame(15, $streamEnds->first()->usage->inputTokens);
        $this->assertSame(3, $streamEnds->first()->usage->outputTokens);
    }

    public function testItDoesNotExecuteStreamedToolCallsOnTheFinalStep(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopCountingTool', [], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
                returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new TextUsage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 1),
            null,
        ));

        $this->assertSame(0, $tool->calls);
        $this->assertSame(1, $gateway->streamCalls);
        $this->assertCount(1, collect($events)->whereInstanceOf(ToolResultEvent::class));
        $this->assertFalse(collect($events)->whereInstanceOf(ToolResultEvent::class)->first()->successful);
        $this->assertSame('The agent reached its maximum number of steps without running this tool call.', collect($events)->whereInstanceOf(ToolResultEvent::class)->first()->error);
        $this->assertCount(1, collect($events)->whereInstanceOf(StreamEnd::class));
    }

    #[DataProvider('nonPositiveStepCounts')]
    public function testItClampsNonPositiveMaxStepsToAtLeastOneTurn(int $maxSteps): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse(
                text: 'hi',
                toolCalls: [],
                finishReason: FinishReason::Stop,
                usage: new TextUsage(1, 1),
                meta: new Meta('fake', 'model'),
            ),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            new TextGenerationOptions(maxSteps: $maxSteps),
            null,
        );

        $this->assertSame(1, $gateway->generateCalls);
        $this->assertSame('hi', $response->text);
    }

    /**
     * Provide non-positive step limits.
     */
    public static function nonPositiveStepCounts(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    public function testItAccumulatesStreamedUsageAcrossMultiStepTurns(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopCountingTool', [], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call', $toolCall, time())],
                returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new TextUsage(10, 1), meta: new Meta('fake', 'model')),
            ),
            textGenerationLoopStreamStep(
                events: [new TextDelta('delta', 'msg-1', 'done', time())],
                returns: new StepResponse(text: 'done', toolCalls: [], finishReason: FinishReason::Stop, usage: new TextUsage(5, 2), meta: new Meta('fake', 'model')),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        ));

        $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

        $this->assertInstanceOf(StreamEnd::class, $streamEnd);
        $this->assertSame(15, $streamEnd->usage->inputTokens);
        $this->assertSame(3, $streamEnd->usage->outputTokens);
        $this->assertSame(FinishReason::Stop->value, $streamEnd->reason);
    }

    public function testItThrowsWhenGenerationToolCallsDoNotMatchLocalTools(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse(
                text: '',
                toolCalls: [new ToolCall('call-1', 'MissingTool', [], 'call-1')],
                finishReason: FinishReason::ToolCalls,
                usage: new TextUsage(10, 1),
                meta: new Meta('fake', 'model'),
                continuationToken: 'response-1',
            ),
        ]);

        $this->expectException(NoSuchToolException::class);
        $this->expectExceptionMessage("Model tried to call unavailable tool 'MissingTool'.");
        (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            new TextGenerationOptions(agent: new TextGenerationLoopAgent),
            null,
        );
    }

    public function testItThrowsWhenStreamingToolCallsDoNotMatchLocalTools(): void
    {
        $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
                returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new TextUsage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
            ),
        ]);

        $this->expectException(NoSuchToolException::class);
        iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            null,
            null,
        ));
    }

    public function testItRepairsMissingGenerationToolCallsForAgentsWithTheRepairAttribute(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('Done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool, new WebSearch],
            null,
            new TextGenerationOptions(maxSteps: 2, agent: new TextGenerationLoopRepairingAgent),
            null,
        );

        $this->assertSame(2, $gateway->generateCalls);
        $this->assertSame('Done', $response->text);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame("Tool 'MissingTool' does not exist. Available tools: TextGenerationLoopCountingTool.", $response->toolResults[0]->result);
    }

    public function testItBudgetsAnImplicitStepForARepairedToolCall(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $toolCall = new ToolCall('call-2', 'TextGenerationLoopCountingTool', [], 'call-2');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('Done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
            null,
        );

        $this->assertSame(3, $gateway->generateCalls);
        $this->assertFalse($gateway->contexts[1]->isFinalStep);
        $this->assertTrue($gateway->contexts[2]->isFinalStep);
        $this->assertSame(1, $tool->calls);
        $this->assertSame('Done', $response->text);
    }

    public function testItExecutesLocalToolsWhenProviderHostedToolsAreAlsoRegistered(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopCountingTool', [], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('Done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [new WebSearch, $tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        );

        $this->assertSame(1, $tool->calls);
        $this->assertSame(2, $gateway->generateCalls);
        $this->assertSame('Done', $response->text);
    }

    public function testItRepairsMissingStreamedToolCallsForAgentsWithTheRepairAttribute(): void
    {
        $tool = new TextGenerationLoopCountingTool;
        $missingToolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
        $toolCall = new ToolCall('call-2', 'TextGenerationLoopCountingTool', [], 'call-2');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event', $missingToolCall, time())],
                returns: new StepResponse('', [$missingToolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            ),
            textGenerationLoopStreamStep(
                events: [new ToolCallEvent('tool-call-event-2', $toolCall, time())],
                returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            ),
            textGenerationLoopStreamStep(
                events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
                returns: new StepResponse('Done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
            ),
        ]);

        $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$tool],
            null,
            new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
            null,
        ));

        $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class)->values();
        $repairResult = $toolResults[0];

        $this->assertSame(3, $gateway->streamCalls);
        $this->assertSame(1, $tool->calls);
        $this->assertCount(2, $toolResults);
        $this->assertSame("Tool 'MissingTool' does not exist. Available tools: TextGenerationLoopCountingTool.", $repairResult->toolResult->result);
        $this->assertFalse($repairResult->successful);
        $this->assertSame($repairResult->toolResult->result, $repairResult->error);
        $this->assertTrue($toolResults[1]->successful);
    }

    public function testItReportsNoAvailableToolsWhileRepairingMissingToolCalls(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('Done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            new TextGenerationOptions(maxSteps: 2, agent: new TextGenerationLoopRepairingAgent),
            null,
        );

        $this->assertSame("Tool 'MissingTool' does not exist. Available tools: none.", $response->toolResults[0]->result);
    }

    public function testItThrowsWhenAnApprovedToolIsUnavailableDespiteTheRepairAttribute(): void
    {
        $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');

        $this->expectException(NoSuchToolException::class);
        (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [new AssistantMessage('', collect([$toolCall]))],
            [],
            null,
            new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
            null,
            ['call-1' => Decision::approve()],
        );
    }

    public function testItThrowsWhenATurnCompletesWithoutAStepResponse(): void
    {
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(events: [new TextDelta('text-delta', 'message-1', 'partial', time())]),
        ]);

        $this->expectException(StreamErrorException::class);
        $this->expectExceptionMessage('The provider ended the stream without completing the step.');
        iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            null,
            null,
        ));
    }

    public function testItYieldsTheErrorAndThenThrowsItWhenATurnErrorsWithoutAStreamEnd(): void
    {
        $error = new Error('error-1', 'server_error', 'Server overloaded', false, time());

        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(events: [$error]),
        ]);

        $events = [];
        $thrown = null;

        try {
            foreach ((new TextGenerationLoop($gateway))->stream(
                'invocation-1',
                textGenerationLoopProvider(),
                'model',
                null,
                [],
                [],
                null,
                null,
                null,
            ) as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $thrown = $exception;
        }

        // The consumer still sees the provider's own error event before the step fails...
        $this->assertCount(0, collect($events)->whereInstanceOf(StreamEnd::class));
        $this->assertCount(1, collect($events)->whereInstanceOf(Error::class));
        $this->assertSame($error, $thrown?->error);
        $this->assertSame('Server overloaded', $thrown?->getMessage());
    }

    public function testItSendsTheDeferredToolsAsRegularToolsWhenTheProviderDoesNotSupportToolSearch(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);
        $regular = new TextGenerationLoopCountingTool;
        $deferred = new TextGenerationLoopCountingTool;

        (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$regular, new ToolSearch(tools: [$deferred])],
            null,
            null,
            null,
        );

        $this->assertSame([[$regular, $deferred]], $gateway->tools);
    }

    public function testItSendsTheDeferredToolsAsRegularToolsOnAStreamedGenerationWhenTheProviderDoesNotSupportToolSearch(): void
    {
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [],
                returns: new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
            ),
        ]);
        $regular = new TextGenerationLoopCountingTool;
        $deferred = new TextGenerationLoopCountingTool;

        iterator_to_array((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$regular, new ToolSearch(tools: [$deferred])],
            null,
            null,
            null,
        ));

        $this->assertSame([[$regular, $deferred]], $gateway->tools);
    }

    public function testItDropsProviderToolsTheProviderDoesNotSupportBeforeCallingTheGateway(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);
        $regular = new TextGenerationLoopCountingTool;
        $webSearch = new WebSearch;

        $provider = m::mock(TextProvider::class, SupportsWebSearch::class);
        $provider->shouldReceive('name')->andReturn('fake');

        (new TextGenerationLoop($gateway))->generate(
            $provider,
            'model',
            null,
            [],
            [$regular, new WebFetch, $webSearch, new CodeExecution],
            null,
            null,
            null,
        );

        $this->assertSame([[$regular, $webSearch]], $gateway->tools);
    }

    public function testItRejectsMoreThanOneToolSearchWrapperOnASupportingProvider(): void
    {
        $gateway = new TextGenerationLoopFakeGateway;
        $tools = [
            new TextGenerationLoopCountingTool,
            new ToolSearch(tools: [new TextGenerationLoopCountingTool]),
            new ToolSearch(tools: [new TextGenerationLoopCountingTool]),
        ];

        try {
            (new TextGenerationLoop($gateway))->generate(
                textGenerationLoopToolSearchProvider(),
                'model',
                null,
                [],
                $tools,
                null,
                null,
                null,
            );
            $this->fail('Multiple tool search wrappers must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('single tool search wrapper', $exception->getMessage());
        }

        $this->assertSame(0, $gateway->generateCalls);
    }

    public function testItAllowsASingleToolSearchWrapperOnASupportingProvider(): void
    {
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopToolSearchProvider(),
            'model',
            null,
            [],
            [new TextGenerationLoopCountingTool, new ToolSearch(tools: [new TextGenerationLoopCountingTool])],
            null,
            null,
            null,
        );

        $this->assertSame(1, $gateway->generateCalls);
        $this->assertSame('done', $response->text);
    }

    public function testItPreservesTheGenerationLoopProtectedExtensionSignatures(): void
    {
        $this->assertInstanceOf(TextGenerationLoop::class, new ExtendedTextGenerationLoop(new TextGenerationLoopFakeGateway));
    }

    public function testAnApprovalResumeSettlesAnEarlierAbandonedPause(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $abandonedCall = new ToolCall('call-old', 'TextGenerationLoopApprovableTool', ['value' => 'abandoned'], 'call-old');
        $pendingCall = new ToolCall('call-new', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-new');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [
                new AssistantMessage('', collect([$abandonedCall])),
                new Message('user', 'Never mind, use the other file.'),
                new AssistantMessage('', collect([$pendingCall])),
            ],
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            ['call-new' => Decision::approve()],
        );

        $settled = $gateway->messages[0][1];

        $this->assertSame([['value' => 'approved']], $tool->handledArguments);
        $this->assertInstanceOf(ToolResultMessage::class, $settled);
        $this->assertSame('call-old', $settled->toolResults->first()->id);
        $this->assertStringContainsString('not approved', $settled->toolResults->first()->result);
        $this->assertSame('done', $response->text);
    }

    public function testAGatedToolCallOnTheFinalStepPausesInsteadOfBeingExhausted(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $ungated = new TextGenerationLoopCountingTool;
        $gatedCall = new ToolCall('call-gated', 'TextGenerationLoopApprovableTool', ['value' => 'danger'], 'call-gated');
        $ungatedCall = new ToolCall('call-ungated', 'TextGenerationLoopCountingTool', [], 'call-ungated');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [$gatedCall, $ungatedCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [$gated, $ungated],
            null,
            new TextGenerationOptions(maxSteps: 1),
            null,
        );

        $this->assertSame(0, $gated->calls);
        $this->assertSame(0, $ungated->calls);
        $this->assertTrue($response->hasPendingApprovals());
        $this->assertCount(1, $response->pendingApprovals);
        $this->assertSame('call-gated', $response->pendingApprovals[0]->id);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('call-ungated', $response->toolResults[0]->id);
        $this->assertStringContainsString('maximum number of steps', $response->toolResults[0]->result);
    }

    public function testAPreValidatedStreamedResumeExecutesTheApprovedToolExactlyOnce(): void
    {
        $tool = new TextGenerationLoopApprovableTool;
        $toolCall = new ToolCall('call-1', 'TextGenerationLoopApprovableTool', ['value' => 'approved'], 'call-1');
        $gateway = new TextGenerationLoopFakeGateway(streams: [
            textGenerationLoopStreamStep(
                events: [new TextDelta('text-delta', 'message-1', 'done', time())],
                returns: new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
            ),
        ]);

        $loop = new TextGenerationLoop($gateway);
        $messages = [new AssistantMessage('', collect([$toolCall]))];
        $decision = ['call-1' => Decision::approve()];

        $loop->validateApproval($decision, $messages, [$tool]);

        iterator_to_array($loop->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            $messages,
            [$tool],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
            $decision,
        ));

        $this->assertSame(1, $tool->calls);
    }

    public function testASubAgentReportsItsOutputBeforeTheToolResultItSettlesOn(): void
    {
        $tool = textGenerationLoopAgentTool();
        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $events = collect(textGenerationLoopSubAgentStream([$tool], [$toolCall]));

        [$preliminary, $settled] = $events->whereInstanceOf(ToolResultEvent::class)
            ->partition(fn (ToolResultEvent $event): bool => $event->preliminary);

        $this->assertCount(1, $preliminary);
        $this->assertSame('invocation-1', $preliminary->first()->invocationId);
        $this->assertSame('call-sub-agent', $preliminary->first()->toolResult->id);
        $this->assertSame('research_agent', $preliminary->first()->toolResult->name);
        $this->assertSame('sub-agent result', $preliminary->first()->toolResult->result);
        $this->assertLessThan($events->search($settled->first()), $events->search($preliminary->first()));
        $this->assertSame('sub-agent result', $settled->first()->toolResult->result);
    }

    public function testASubAgentReportsItsOutputAgainOnceItHasWrittenEnoughOfIt(): void
    {
        $tool = textGenerationLoopAgentTool(fn (): Generator => yield from array_map(
            fn (int $index): TextDelta => new TextDelta("sub-delta-{$index}", 'sub-message', str_repeat('a', 100), time()),
            range(1, 6),
        ));
        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $preliminary = collect(textGenerationLoopSubAgentStream([$tool], [$toolCall]))
            ->whereInstanceOf(ToolResultEvent::class)
            ->filter(fn (ToolResultEvent $event): bool => $event->preliminary);

        // Six 100 character deltas cross the 240 byte threshold twice, at 300 and at 600...
        $this->assertSame([300, 600], $preliminary->map(fn (ToolResultEvent $event): int => strlen($event->toolResult->result))->values()->all());
    }

    public function testPreliminaryOutputJoinsTheTextOfSeparateSubAgentStepsTheWayTheFinalResultDoes(): void
    {
        $events = [
            new TextDelta('sub-delta-1', 'sub-message-1', 'First step.', time()),
            new TextDelta('sub-delta-2', 'sub-message-2', 'Second step.', time()),
            new StreamEnd('sub-end', FinishReason::Stop->value, new TextUsage, time()),
        ];

        $tool = textGenerationLoopAgentTool(fn (): Generator => yield from $events);
        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $streamed = collect(textGenerationLoopSubAgentStream([$tool], [$toolCall]));

        [$preliminary, $settled] = $streamed->whereInstanceOf(ToolResultEvent::class)
            ->partition(fn (ToolResultEvent $event): bool => $event->preliminary);

        $this->assertSame(TextDelta::combine($events), $preliminary->last()->toolResult->result);
        $this->assertSame($settled->first()->toolResult->result, $preliminary->last()->toolResult->result);
    }

    public function testASubAgentRunsSynchronouslyThroughTheNonStreamingLoop(): void
    {
        $agent = m::mock(Agent::class, CanActAsTool::class);
        $agent->shouldReceive('name')->andReturn('research_agent');
        $agent->shouldReceive('prompt')->once()->andReturn(new AgentResponse(
            'sub-invocation',
            'synchronous result',
            new TextUsage(3, 4),
            new Meta('fake', 'sub-model')
        ));
        $agent->shouldNotReceive('stream');

        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
        $gateway = new TextGenerationLoopFakeGateway([
            new StepResponse('', [$toolCall], FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
            new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        ]);

        $response = (new TextGenerationLoop($gateway))->generate(
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [new AgentTool($agent)],
            null,
            new TextGenerationOptions(maxSteps: 2),
            null,
        );

        $this->assertSame('done', $response->text);
        $this->assertSame('synchronous result', $response->toolResults[0]->result);
    }

    public function testASubAgentIsNotExecutedOnTheFinalStreamedStep(): void
    {
        $agent = m::mock(Agent::class, CanActAsTool::class);
        $agent->shouldReceive('name')->andReturn('research_agent');
        $agent->shouldNotReceive('prompt');
        $agent->shouldNotReceive('stream');

        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $events = collect(textGenerationLoopSubAgentStream([new AgentTool($agent)], [$toolCall], maxSteps: 1));

        $this->assertCount(0, $events->whereInstanceOf(ToolResultEvent::class)->filter(fn (ToolResultEvent $event): bool => $event->preliminary));
        $this->assertStringContainsString('maximum number of steps', $events->whereInstanceOf(ToolResultEvent::class)->first()->toolResult->result);
    }

    public function testMultipleSubAgentCallsReportTheirOutputUnderTheirOwnToolCallIds(): void
    {
        $tool = textGenerationLoopAgentTool();
        $firstCall = new ToolCall('call-first', 'research_agent', ['task' => 'First'], 'call-first');
        $secondCall = new ToolCall('call-second', 'research_agent', ['task' => 'Second'], 'call-second');

        $preliminary = collect(textGenerationLoopSubAgentStream([$tool], [$firstCall, $secondCall]))
            ->whereInstanceOf(ToolResultEvent::class)
            ->filter(fn (ToolResultEvent $event): bool => $event->preliminary);

        $this->assertSame(['call-first', 'call-second'], $preliminary->map(fn (ToolResultEvent $event): string => $event->toolResult->id)->values()->all());
    }

    public function testToolInvocationEventsFireAroundASubAgentInTheStreamedLoop(): void
    {
        $tool = textGenerationLoopAgentTool();
        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $invoking = [];
        $invoked = [];

        $dispatcher = new Dispatcher;

        $dispatcher->listen(InvokingTool::class, function (InvokingTool $event) use (&$invoking): void {
            $invoking[] = $event->tool::class;
        });

        $dispatcher->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
            $invoked[] = $event->result;
        });

        $provider = textGenerationLoopProvider();

        textGenerationLoopSubAgentStream([$tool], [$toolCall], context: new RunContext(
            'invocation-1',
            m::mock(Agent::class),
            $provider,
            'model',
            $dispatcher,
        ));

        $this->assertSame([AgentTool::class], $invoking);
        $this->assertSame(['sub-agent result'], $invoked);
    }

    public function testASubAgentNamesTheToolCallItWasDelegatedFromForTheWholeOfItsRun(): void
    {
        $parents = [];

        $tool = textGenerationLoopAgentTool(function () use (&$parents): Generator {
            foreach (range(1, 3) as $index) {
                $parents[] = ParentInvocation::current();

                yield new TextDelta("sub-delta-{$index}", 'sub-message', 'chunk', time());
            }
        });
        $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $provider = textGenerationLoopProvider();

        textGenerationLoopSubAgentStream([$tool], [$toolCall], context: new RunContext(
            'invocation-1',
            m::mock(Agent::class),
            $provider,
            'model',
            new Dispatcher,
        ));

        $this->assertCount(3, $parents);
        $this->assertSame(['invocation-1'], collect($parents)->pluck(0)->unique()->all());
        $this->assertCount(1, collect($parents)->pluck(1)->unique());
    }

    public function testAGatedToolPausesTheStreamedLoopWhileASubAgentStillReportsItsOutput(): void
    {
        $gated = new TextGenerationLoopApprovableTool;
        $gatedCall = new ToolCall('call-gated', 'TextGenerationLoopApprovableTool', ['value' => 'danger'], 'call-gated');
        $subAgentCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');

        $events = collect(textGenerationLoopSubAgentStream(
            [$gated, textGenerationLoopAgentTool()],
            [$gatedCall, $subAgentCall],
        ));

        $approvalRequests = $events->whereInstanceOf(ToolApprovalRequest::class)->values();

        $this->assertSame(0, $gated->calls);
        $this->assertCount(1, $events->whereInstanceOf(ToolResultEvent::class)->filter(fn (ToolResultEvent $event): bool => $event->preliminary));
        $this->assertCount(1, $approvalRequests);
        $this->assertSame('call-gated', $approvalRequests->first()->pendingApprovals->first()->id);
    }

    public function testEachResultIsPersistedBeforeTheNextToolRuns(): void
    {
        $provider = textGenerationLoopProvider();
        $store = m::mock(ClaimsPendingApprovals::class);
        $claim = new ApprovalClaim('message', 'token');
        $recorded = [];
        $operations = [];
        $store->shouldReceive('claimPendingApprovals')->once()->with('conversation', ['first', 'second', 'third'])
            ->andReturnUsing(function () use (&$operations, $claim): ApprovalClaim {
                $operations[] = 'claim';

                return $claim;
            });
        $store->shouldReceive('recordApprovalResult')->times(3)->with($claim, m::type(ToolResultData::class))
            ->andReturnUsing(function (ApprovalClaim $claim, ToolResultData $result) use (&$operations, &$recorded): void {
                $operations[] = 'record:' . $result->id;
                $recorded[$result->id] = $result;
            });

        $tool = $this->approvalTool();
        $tool->shouldReceive('handle')->twice()->andReturnUsing(function (Request $request) use (&$operations): string {
            $operations[] = 'execute:' . $request->toolCallId();

            return 'done';
        });
        $gateway = m::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->once()->andReturn(new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta));
        $context = new RunContext('invocation', m::mock(Agent::class), $provider, 'model', new Dispatcher, approvalStore: $store, conversationId: 'conversation');

        (new TextGenerationLoop($gateway))->generate(
            $provider,
            'model',
            '',
            messages: [new AssistantMessage('', collect([
                new ToolCall('first', 'tool', [], 'result-first'),
                new ToolCall('second', 'tool', []),
                new ToolCall('third', 'tool', []),
            ]))],
            tools: [$tool],
            approval: ['first' => Decision::edit(['value' => 'edited']), 'second' => Decision::reject('Denied'), 'third' => Decision::approve()],
            context: $context,
        );

        $this->assertSame(['claim', 'execute:first', 'record:first', 'record:second', 'execute:third', 'record:third'], $operations);
        $this->assertSame(['first', 'second', 'third'], array_keys($recorded));
        $this->assertSame(['value' => 'edited'], $recorded['first']->arguments);
        $this->assertSame('result-first', $recorded['first']->resultId);
        $this->assertTrue($recorded['second']->denied);
    }

    public function testAnUnavailableClaimPreventsToolExecution(): void
    {
        $provider = textGenerationLoopProvider();
        $gateway = m::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->andReturn(new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta));
        $store = m::mock(ClaimsPendingApprovals::class);
        $store->shouldReceive('claimPendingApprovals')->once()->with('conversation', ['call'])->andReturnNull();
        $store->shouldNotReceive('recordApprovalResult');
        $tool = $this->approvalTool();
        $tool->shouldNotReceive('handle');
        $context = new RunContext('invocation', m::mock(Agent::class), $provider, 'model', new Dispatcher, approvalStore: $store, conversationId: 'conversation');

        $this->expectException(ApprovalMismatchException::class);

        (new TextGenerationLoop($gateway))->generate(
            $provider,
            'model',
            '',
            messages: [new AssistantMessage('', collect([new ToolCall('call', 'tool', [])]))],
            tools: [$tool],
            approval: ['call' => Decision::approve()],
            context: $context,
        );
    }

    public function testCancellationIsNotRecordedAsACompletedToolFailure(): void
    {
        $provider = textGenerationLoopProvider();
        $gateway = m::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->andReturn(new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta));
        $store = m::mock(ClaimsPendingApprovals::class);
        $claim = new ApprovalClaim('message', 'token');
        $store->shouldReceive('claimPendingApprovals')->once()->with('conversation', ['call'])->andReturn($claim);
        $store->shouldNotReceive('recordApprovalResult');
        $exception = new CanceledException('Canceled while executing the approved tool.');
        $tool = $this->approvalTool();
        $tool->shouldReceive('handle')->once()->andThrow($exception);
        $context = new RunContext('invocation', m::mock(Agent::class), $provider, 'model', new Dispatcher, approvalStore: $store, conversationId: 'conversation');

        try {
            (new TextGenerationLoop($gateway))->generate(
                $provider,
                'model',
                '',
                messages: [new AssistantMessage('', collect([new ToolCall('call', 'tool', [])]))],
                tools: [$tool],
                approval: ['call' => Decision::approve()],
                context: $context,
            );
            $this->fail('Cancellation was swallowed.');
        } catch (CanceledException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame($claim, $context->approvalClaim());
    }

    /**
     * Create an approval-gated tool for the execution loop.
     */
    private function approvalTool(): Tool&Approvable&m\MockInterface
    {
        $tool = m::mock(Tool::class, Approvable::class);
        $tool->shouldReceive('name')->andReturn('tool');
        $tool->shouldReceive('shouldRequestApproval')->andReturn(Approval::required());

        return $tool;
    }
}

/**
 * Create a text-only provider.
 */
function textGenerationLoopProvider(): TextProvider
{
    $provider = m::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn('fake');

    return $provider;
}

/**
 * Create a provider supporting tool search.
 */
function textGenerationLoopToolSearchProvider(): TextProvider
{
    $provider = m::mock(TextProvider::class, SupportsToolSearch::class);
    $provider->shouldReceive('name')->andReturn('fake');

    return $provider;
}

/**
 * Describe a streamed gateway response.
 *
 * @param array<int, object> $events
 */
function textGenerationLoopStreamStep(array $events = [], ?StepResponse $returns = null): array
{
    return [$events, $returns];
}

class TextGenerationLoopFakeGateway implements StepTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /** @var StepContext[] */
    public array $contexts = [];

    public array $messages = [];

    public array $tools = [];

    /**
     * Supply the gateway's responses in execution order.
     */
    public function __construct(
        public array $steps = [],
        public array $streams = [],
    ) {
    }

    /**
     * Record a generation request and return the next response.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        ++$this->generateCalls;
        $this->contexts[] = $stepContext;
        $this->messages[] = $messages;
        $this->tools[] = $tools;

        return array_shift($this->steps);
    }

    /**
     * Record a streaming request and yield the next response.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        ++$this->streamCalls;
        $this->contexts[] = $stepContext;
        $this->tools[] = $tools;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}

class TextGenerationLoopCountingTool implements Tool
{
    public int $calls = 0;

    /**
     * Describe the counting tool.
     */
    public function description(): string
    {
        return 'Counts invocations.';
    }

    /**
     * Count this execution.
     */
    public function handle(Request $request): string
    {
        ++$this->calls;

        return 'counted';
    }

    /**
     * Describe the tool's inputs.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class TextGenerationLoopApprovableTool extends TextGenerationLoopCountingTool implements Approvable
{
    use InteractsWithApprovals;

    public array $handledArguments = [];

    public ?string $approvalToolCallId = null;

    /**
     * Require a human decision and record the call being evaluated.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $this->approvalToolCallId = $request->toolCallId();

        return Approval::required('Needs a human');
    }

    /**
     * Record the approved inputs and execute the tool.
     */
    public function handle(Request $request): string
    {
        ++$this->calls;
        $this->handledArguments[] = $request->all();

        return 'handled ' . $request['value'];
    }
}

#[RepairToolCalls]
class TextGenerationLoopRepairingAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}

class TextGenerationLoopAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}

class ExtendedTextGenerationLoop extends TextGenerationLoop
{
    /**
     * Preserve the upstream step extension point.
     */
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        return parent::stepToolResults($result, $isFinalStep, $tools, $context);
    }

    /**
     * Preserve the upstream approval extension point.
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        return parent::approvalAwareToolResults($toolCalls, $tools, $isFinalStep, $context);
    }
}

/**
 * Create a sub-agent that must use streaming delivery.
 */
function textGenerationLoopAgentTool(Closure|string $text = 'sub-agent result'): AgentTool
{
    $agent = m::mock(Agent::class, CanActAsTool::class);
    $agent->shouldReceive('name')->andReturn('research_agent');
    $agent->shouldNotReceive('prompt');
    $agent->shouldReceive('stream')->atLeast()->once()->andReturnUsing(fn (): StreamableAgentResponse => new StreamableAgentResponse(
        'sub-invocation',
        $text instanceof Closure ? $text : fn (): Generator => yield from [
            (new TextDelta('sub-delta', 'sub-message', $text, time()))->withInvocationId('sub-invocation'),
            (new StreamEnd('sub-end', FinishReason::Stop->value, new TextUsage(3, 4), time()))->withInvocationId('sub-invocation'),
        ],
        new Meta('fake', 'sub-model'),
    ));

    return new AgentTool($agent);
}

/**
 * Stream a parent run that requests the given sub-agent tools.
 *
 * @return array<int, StreamEvent>
 */
function textGenerationLoopSubAgentStream(array $tools, array $toolCalls, int $maxSteps = 2, ?RunContext $context = null): array
{
    $steps = [textGenerationLoopStreamStep(
        events: [],
        returns: new StepResponse('', $toolCalls, FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
    )];

    if ($maxSteps > 1) {
        $steps[] = textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-2', 'done', time())],
            returns: new StepResponse('done', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
        );
    }

    return iterator_to_array((new TextGenerationLoop(new TextGenerationLoopFakeGateway(streams: $steps)))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        $tools,
        null,
        new TextGenerationOptions(maxSteps: $maxSteps),
        null,
        context: $context,
    ));
}
