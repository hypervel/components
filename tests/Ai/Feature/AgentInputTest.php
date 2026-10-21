<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\AgentInput;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\QueuedAgentPrompt;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ConversationalAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use LogicException;

class AgentInputTest extends TestCase
{
    public function testAUserMessageMayBeGivenAsThePrompt(): void
    {
        AssistantAgent::fake(['Hello there.']);

        $response = (new AssistantAgent)->prompt(
            new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')])
        );

        $this->assertSame('Hello there.', $response->text);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
            && $prompt->attachments->count() === 1
            && $prompt->attachments->first() instanceof Base64Image);
    }

    public function testAUserMessageMayBeGivenAsThePromptWhenStreaming(): void
    {
        AssistantAgent::fake(['Hello there.']);

        iterator_to_array((new AssistantAgent)->stream(new UserMessage('Hello')));

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Hello');
    }

    public function testAgentInputResolvesToItsUserMessage(): void
    {
        AssistantAgent::fake(['Hello there.']);

        (new AssistantAgent)->prompt($this->agentInput(message: new UserMessage('From agent input')));

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'From agent input');
    }

    public function testAgentInputResolvesToItsApprovalDecisionsBeforeItsUserMessage(): void
    {
        Queue::fake();
        AssistantAgent::fake();

        (new AssistantAgent)->queue($this->agentInput(
            message: new UserMessage('Ignored'),
            decisions: Decision::approveAll(),
        ));

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
    }

    public function testEmptyAgentInputIsRejected(): void
    {
        AssistantAgent::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The agent input contains no user message or approval decisions.');

        (new AssistantAgent)->prompt($this->agentInput());
    }

    public function testAdHocMessageHistoryIsSentAheadOfThePrompt(): void
    {
        AssistantAgent::fake(['How can I help?']);

        $history = [
            new UserMessage('Hello'),
            new AssistantMessage('Hi! How can I help you today?'),
        ];

        (new AssistantAgent)->withMessages($history)->prompt('What is Hypervel?');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messages === $history
            && $prompt->prompt === 'What is Hypervel?');
    }

    public function testAdHocMessageHistoryMayBeGivenAsRawArrays(): void
    {
        AssistantAgent::fake(['Sure.']);

        (new AssistantAgent)
            ->withMessages([['role' => 'user', 'content' => 'Hello']])
            ->prompt('Continue');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => count($prompt->messages) === 1
            && $prompt->messages[0]->content === 'Hello');
    }

    public function testAdHocMessageHistoryMayBeGivenAsUiMessageArrays(): void
    {
        AssistantAgent::fake(['Sure.']);

        (new AssistantAgent)
            ->withMessages([['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hello']]]])
            ->prompt('Continue');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => count($prompt->messages) === 1
            && $prompt->messages[0]->content === 'Hello');
    }

    public function testAdHocMessageHistoryMayNotBeCombinedWithAConversationalAgent(): void
    {
        $this->expectException(LogicException::class);

        (new ConversationalAgent)->withMessages([new UserMessage('Hello')]);
    }

    public function testAdHocMessageHistoryDoesNotLeakIntoALaterPrompt(): void
    {
        AssistantAgent::fake(['First.', 'Second.']);

        $agent = new AssistantAgent;

        $agent->withMessages([new UserMessage('Hello')])->prompt('First prompt');
        $agent->prompt('Second prompt');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt'
            && count($prompt->messages) === 1);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt'
            && $prompt->messages === null);
    }

    public function testAgentInputResolvesToItsApprovalDecisionsWhenBroadcastingOnTheQueue(): void
    {
        Queue::fake();
        AssistantAgent::fake();

        (new AssistantAgent)->broadcastOnQueue($this->agentInput(
            message: new UserMessage('Ignored'),
            decisions: Decision::approveAll(),
        ), []);

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
    }

    public function testAUserMessageKeepsItsAttachmentsWhenBroadcastingOnTheQueue(): void
    {
        Queue::fake();
        AssistantAgent::fake();

        (new AssistantAgent)->broadcastOnQueue(
            new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')]),
            []
        );

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
            && count($prompt->attachments) === 1
            && $prompt->attachments[0] instanceof Base64Image);
    }

    /**
     * Create an input with the given message and decisions.
     */
    protected function agentInput(?UserMessage $message = null, ?Decisions $decisions = null): AgentInput
    {
        return new class($message, $decisions) implements AgentInput {
            /**
             * Create the input.
             */
            public function __construct(private ?UserMessage $message, private ?Decisions $decisions)
            {
            }

            /**
             * Get the user message.
             */
            public function message(): ?UserMessage
            {
                return $this->message;
            }

            /**
             * Get the approval decisions.
             */
            public function decisions(): ?Decisions
            {
                return $this->decisions;
            }
        };
    }
}
