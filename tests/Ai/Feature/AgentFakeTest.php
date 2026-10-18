<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Jobs\InvokeAgent;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\QueuedAgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Ai\Responses\StructuredTextResponse;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ConversationalAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\EmptySchemaStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

class AgentFakeTest extends TestCase
{
    /**
     * Clear serialized callback results.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['agentResponse']);

        parent::tearDown();
    }

    public function testAgentsCanBeFaked(): void
    {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt): string => 'Second response (' . $prompt . ')',
            new TextResponse('Third response', new TextUsage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertSame('First response', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertSame('Second response (Second prompt)', $response->text);

        $response = (new AssistantAgent)->prompt('Third prompt');
        $this->assertSame('Third response', $response->text);

        // Assertion tests...
        AssistantAgent::assertPrompted('First prompt');
        AssistantAgent::assertPromptedTimes(3);
        AssistantAgent::assertNotPrompted('Missing prompt');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');
    }

    public function testCanAssertAgentWasNeverPrompted(): void
    {
        AssistantAgent::fake();

        AssistantAgent::assertNeverPrompted();
    }

    public function testFakeResponsesMayExposeARawHttpResponse(): void
    {
        AssistantAgent::fake([
            (new TextResponse('Hello', new TextUsage, new Meta))->withRawResponse(new Response(
                new Psr7Response(200, ['x-ratelimit-remaining-requests' => '99'], '{}')
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi');

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('99', $response->raw->header('x-ratelimit-remaining-requests'));
    }

    public function testAgentsCanBeFakedWithNoPredefinedResponses(): void
    {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertSame('Fake response for prompt: First prompt', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertSame('Fake response for prompt: Second prompt', $response->text);
    }

    public function testAgentsCanBeFakedWithASingleClosureThatIsInvokedForEveryPrompt(): void
    {
        AssistantAgent::fake(fn (string $prompt): string => 'Fake response for prompt: ' . $prompt);

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertSame('Fake response for prompt: First prompt', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertSame('Fake response for prompt: Second prompt', $response->text);
    }

    public function testAgentsCanPreventStrayPrompts(): void
    {
        AssistantAgent::fake()->preventStrayPrompts();

        $this->expectException(RuntimeException::class);
        (new AssistantAgent)->prompt('First prompt');
    }

    public function testAgentsWithStructuredOutputCanBeFaked(): void
    {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            fn (string $prompt): array => ['symbol' => 'Ag (' . $prompt . ')'],
            new StructuredTextResponse(
                ['symbol' => 'Pb'],
                json_encode(['symbol' => 'Pb']),
                new TextUsage,
                new Meta,
            ),
        ]);

        $response = (new StructuredAgent)->prompt('Gold prompt');
        $this->assertSame('Au', $response['symbol']);

        $response = (new StructuredAgent)->prompt('Silver prompt');
        $this->assertSame('Ag (Silver prompt)', $response['symbol']);

        $response = (new StructuredAgent)->prompt('Lead prompt');
        $this->assertSame('Pb', $response['symbol']);
    }

    public function testAgentsWithStructuredOutputCanBeFakedWithNoPredefinedResponses(): void
    {
        StructuredAgent::fake();

        $response = (new StructuredAgent)->prompt('Gold prompt');

        $this->assertIsString($response['symbol']);
    }

    public function testFakeClosuresCanThrowExceptions(): void
    {
        AssistantAgent::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        $this->expectException(Exception::class);
        (new AssistantAgent)->prompt('Test prompt');
    }

    public function testStructuredAgentsWithEmptySchemasFallBackToATextResponse(): void
    {
        EmptySchemaStructuredAgent::fake([
            new TextResponse('Hello', new TextUsage, new Meta),
        ]);

        $response = (new EmptySchemaStructuredAgent)->prompt('Anything');

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertNotInstanceOf(StructuredAgentResponse::class, $response);
        $this->assertSame('Hello', $response->text);
    }

    public function testAgentsCanFakePausedApprovalResponsesAndAssertResumePrompts(): void
    {
        ConversationalAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]),
            'Resumed',
        ]);

        $response = (new ConversationalAgent)->prompt('Delete config/app.php');

        $this->assertTrue($response->hasPendingApprovals());
        $this->assertCount(1, $response->pendingApprovals);

        (new ConversationalAgent)->prompt(Decisions::from(['call-1' => true]));

        ConversationalAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });
    }

    public function testFakedPausedApprovalResponsesPersistThePendingToolCall(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $approval = new PendingApproval('call-1', 'ApprovableNumberGenerator', [], 'Needs approval');

        RememberingApprovableAgent::fake([AgentResponse::fakeWithPendingApprovals([$approval])]);

        $response = (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt('Generate a number');

        $this->assertEquals([$approval], (new DatabaseConversationStore)->pendingApprovalsFor($response->conversationId));
    }

    public function testAgentStreamsCanBeFaked(): void
    {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt): string => 'Second response (' . $prompt . ')',
            new TextResponse('Third response', new TextUsage, new Meta),
        ]);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn (): true => true);
        $this->assertSame('First response', $response->text);
        $this->assertCount(6, $response->events);

        $response = (new AssistantAgent)->stream('Second prompt');
        $response->each(fn (): true => true);
        $this->assertSame('Second response (Second prompt)', $response->text);
        $this->assertCount(8, $response->events);

        $response = (new AssistantAgent)->stream('Third prompt');
        $response->each(fn (): true => true);
        $this->assertSame('Third response', $response->text);
        $this->assertCount(6, $response->events);
    }

    public function testFakedAgentsCanStreamTheReasoningThatPrecededAnAnswer(): void
    {
        AssistantAgent::fake([
            AgentResponse::fakeWithReasoning('They want the temperature.', 'It is 12°C.'),
        ]);

        $response = (new AssistantAgent)->stream('How cold is it?');
        $response->each(fn (): true => true);

        $this->assertSame('They want the temperature.', $response->reasoning);
        $this->assertSame('It is 12°C.', $response->text);
        $this->assertCount(1, $response->events->whereInstanceOf(ReasoningStart::class));
        $this->assertCount(1, $response->events->whereInstanceOf(ReasoningEnd::class));
    }

    public function testAFakedStreamReportsNoReasoningWhenTheModelDidNotReason(): void
    {
        AssistantAgent::fake(['It is 12°C.']);

        $response = (new AssistantAgent)->stream('How cold is it?');
        $response->each(fn (): true => true);

        $this->assertSame('', $response->reasoning);
        $this->assertEmpty($response->events->whereInstanceOf(ReasoningDelta::class));
    }

    public function testFakedAgentsCanStreamTheSourcesAnAnswerCited(): void
    {
        AssistantAgent::fake([
            new TextResponse('Hypervel provides an AI SDK.', new TextUsage, new Meta('anthropic', 'test-model', collect([
                new UrlCitation('https://hypervel.org/docs/ai-sdk', 'Hypervel AI'),
            ]))),
        ]);

        $response = (new AssistantAgent)->stream('What does Hypervel AI do?');
        $response->each(fn (): true => true);

        $this->assertCount(1, $response->events->whereInstanceOf(CitationEvent::class));
        $this->assertSame(['https://hypervel.org/docs/ai-sdk'], $response->citations->pluck('url')->all());
    }

    public function testAFakedStreamReportsNoSourcesWhenTheAnswerCitedNothing(): void
    {
        AssistantAgent::fake(['It is 12°C.']);

        $response = (new AssistantAgent)->stream('How cold is it?');
        $response->each(fn (): true => true);

        $this->assertEmpty($response->events->whereInstanceOf(CitationEvent::class));
        $this->assertEmpty($response->citations);
    }

    public function testFakedStreamEventsShareTheResponseInvocationId(): void
    {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)->stream('First prompt');

        $response->each(fn (): true => true);

        foreach ($response->events as $event) {
            $this->assertSame($response->invocationId, $event->invocationId);
        }
    }

    public function testFakedEmptyResponseStreamsWithoutTextEvents(): void
    {
        AssistantAgent::fake(['']);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn (): true => true);

        $this->assertSame('', $response->text);
        $this->assertCount(2, $response->events);
        $this->assertFalse(collect($response->events)->contains(fn (mixed $event): bool => $event instanceof TextStart));
    }

    public function testFakedToolCallsEmitAToolCallEventWhileStreaming(): void
    {
        MultiStepToolAgent::fake([
            new ToolCall('call_123', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent)->stream('Generate a number');
        $response->each(fn (): true => true);

        $events = collect($response->events);

        $toolCall = $events->first(fn (mixed $event): bool => $event instanceof ToolCallEvent);

        $this->assertNotNull($toolCall);
        $this->assertSame('FixedNumberGenerator', $toolCall->toolCall->name);
        $this->assertLessThan(
            $events->search(fn (mixed $event): bool => $event instanceof ToolResultEvent),
            $events->search(fn (mixed $event): bool => $event instanceof ToolCallEvent),
        );
    }

    public function testFakedPausedApprovalResponsesEmitAToolCallEventBeforeTheApprovalRequest(): void
    {
        ConversationalAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]),
        ]);

        $response = (new ConversationalAgent)->stream('Delete config/app.php');
        $response->each(fn (): true => true);

        $events = collect($response->events);

        $toolCall = $events->first(fn (mixed $event): bool => $event instanceof ToolCallEvent);

        $this->assertSame('call-1', $toolCall?->toolCall->id);
        $this->assertLessThan(
            $events->search(fn (mixed $event): bool => $event instanceof ToolApprovalRequest),
            $events->search(fn (mixed $event): bool => $event instanceof ToolCallEvent),
        );
    }

    public function testQueuedAgentsCanBeFaked(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('First prompt');

        AssistantAgent::assertQueued('First prompt');
        AssistantAgent::assertNotQueued('Second prompt');

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');

        AssistantAgent::assertNotQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt');
    }

    public function testQueuedAgentsCanBeFakedAndThenCallbackIsExecuted(): void
    {
        AssistantAgent::fake(['First response']);

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent)->queue('First prompt')->then(static function (AgentResponse $response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        $this->assertInstanceOf(AgentResponse::class, $GLOBALS['agentResponse']);
        $this->assertSame('First response', $GLOBALS['agentResponse']->text);
    }

    public function testQueuedAgentsCanBeFakedAndThenCallbackIsNotExecutedIfQueueIsFaked(): void
    {
        AssistantAgent::fake(['First response']);
        Queue::fake();

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent)->queue('First prompt')->then(static function (AgentResponse $response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        $this->assertNull($GLOBALS['agentResponse']);

        Queue::assertPushed(InvokeAgent::class);
    }

    public function testCanAssertAgentWasNeverQueued(): void
    {
        AssistantAgent::fake();

        AssistantAgent::assertNeverQueued();
    }

    public function testAssertQueuedDoesNotThrowUndefinedKeyWhenAgentWasNeverQueued(): void
    {
        AssistantAgent::fake();

        // Should fail the assertion gracefully, not throw an undefined array key error.
        try {
            AssistantAgent::assertQueued('Some prompt');
            $this->fail('Expected assertion to fail.');
        } catch (AssertionFailedError $assertionFailedError) {
            $this->assertStringContainsString('An expected queued prompt was not received.', $assertionFailedError->getMessage());
        }
    }

    public function testAssertNotQueuedDoesNotThrowUndefinedKeyWhenAgentWasNeverQueued(): void
    {
        AssistantAgent::fake();

        // Should pass gracefully since the agent was never queued.
        AssistantAgent::assertNotQueued('Some prompt');
    }

    public function testQueuedAgentsAcceptAiProviderEnum(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('Enum prompt', provider: Lab::OpenAI);

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt'
            && $prompt->provider === Lab::OpenAI);
    }

    public function testPromptAcceptsAiProviderEnum(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Enum prompt', provider: Lab::Anthropic);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt');
    }

    public function testStreamAcceptsAiProviderEnum(): void
    {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->stream('Enum stream', provider: Lab::Gemini);
        $response->each(fn (): true => true);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Enum stream');
    }

    public function testTimeoutCanBePassedToAgentPrompt(): void
    {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->prompt('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    }

    public function testTimeoutDefaultsToSdkDefaultWhenNotProvided(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Test prompt');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 60);
    }

    public function testTimeoutCanBePassedToAgentStream(): void
    {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->stream('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    }

    public function testTimeoutIsPreservedWhenRevisingAgentPrompt(): void
    {
        AssistantAgent::fake();

        $prompt = new AgentPrompt(
            new AssistantAgent,
            'Original prompt',
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
            150
        );

        $revised = $prompt->revise('Revised prompt');

        $this->assertSame(150, $revised->timeout);
        $this->assertSame('Revised prompt', $revised->prompt);
    }

    public function testRevisingAResumePromptIsANoOpSinceItCarriesNoPromptText(): void
    {
        $prompt = new AgentPrompt(
            new AssistantAgent,
            '',
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => Decision::approve()]),
        );

        $revised = $prompt->append('extra context');

        $this->assertSame($prompt, $revised);
        $this->assertSame('', $revised->prompt);
        $this->assertSame($prompt->approvalDecisions, $revised->approvalDecisions);
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Text generation failed.');
        AssistantAgent::fake([fn (): never => throw $exception, 'Second response']);

        try {
            (new AssistantAgent)->prompt('First');
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('Second response', (new AssistantAgent)->prompt('Second')->text);
    }
}
