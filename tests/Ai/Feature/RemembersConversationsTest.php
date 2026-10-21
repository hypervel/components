<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use Throwable;

class RemembersConversationsTest extends TestCase
{
    use RefreshDatabase;

    public function testItThreadsTheParticipantTypeIntoLatestConversationIdWhenContinuingTheLastConversation(): void
    {
        $participant = new class extends Model {
            protected array $guarded = [];

            /**
             * Get the participant's stored morph type.
             */
            public function getMorphClass(): string
            {
                return 'admin';
            }
        };

        $participant->id = 7;

        $store = new class implements ConversationStore {
            public ?string $receivedType = null;

            public ?string $receivedAgent = null;

            /**
             * Find the participant's last conversation.
             */
            public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
            {
                $this->receivedType = $participantType;
                $this->receivedAgent = $agent;

                return $participantType === 'admin' ? 'conversation-admin' : null;
            }

            /**
             * Store a conversation.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return 'conversation-1';
            }

            /**
             * Store a user message.
             */
            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                return 'user-1';
            }

            /**
             * Store an assistant message.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                return 'assistant-1';
            }

            /**
             * Get the conversation history.
             */
            public function getLatestConversationMessages(string $conversationId, int $limit): Collection
            {
                return new Collection;
            }

            /**
             * Store the approved tool results.
             */
            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
            }
        };

        app()->instance(ConversationStore::class, $store);

        $agent = (new RememberingAssistantAgent)->continueLastConversation($participant);

        // The participant's morph type reaches the store, so it resolves that participant's own conversation...
        $this->assertSame('admin', $store->receivedType);
        $this->assertSame(RememberingAssistantAgent::class, $store->receivedAgent);
        $this->assertSame('conversation-admin', $agent->currentConversation());
    }

    public function testItContinuesTheLastConversationThroughAStoreThatIgnoresTheParticipantType(): void
    {
        $participant = new class {
            public int $id = 7;
        };

        $store = new class implements ConversationStore {
            /**
             * Find the participant's last conversation.
             */
            public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
            {
                return 'conversation-1';
            }

            /**
             * Store a conversation.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return 'conversation-1';
            }

            /**
             * Store a user message.
             */
            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                return 'user-1';
            }

            /**
             * Store an assistant message.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                return 'assistant-1';
            }

            /**
             * Get the conversation history.
             */
            public function getLatestConversationMessages(string $conversationId, int $limit): Collection
            {
                return new Collection;
            }

            /**
             * Store the approved tool results.
             */
            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
            }
        };

        app()->instance(ConversationStore::class, $store);

        $agent = (new RememberingAssistantAgent)->continueLastConversation($participant);

        $this->assertSame('conversation-1', $agent->currentConversation());
    }

    public function testItResolvesTheParticipantIdViaGetKeyForModelsWithCustomPrimaryKeys(): void
    {
        $participant = new class extends Model {
            protected array $guarded = [];

            protected string $primaryKey = 'uuid';

            protected string $keyType = 'string';

            public bool $incrementing = false;
        };

        $participant->uuid = 'uuid-123';

        $store = new class implements ConversationStore {
            public string|int|null $receivedId = null;

            /**
             * Find the participant's last conversation.
             */
            public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
            {
                $this->receivedId = $participantId;

                return 'conversation-1';
            }

            /**
             * Store a conversation.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return 'conversation-1';
            }

            /**
             * Store a user message.
             */
            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                return 'user-1';
            }

            /**
             * Store an assistant message.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                return 'assistant-1';
            }

            /**
             * Get the conversation history.
             */
            public function getLatestConversationMessages(string $conversationId, int $limit): Collection
            {
                return new Collection;
            }

            /**
             * Store the approved tool results.
             */
            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
            }
        };

        app()->instance(ConversationStore::class, $store);

        (new RememberingAssistantAgent)->continueLastConversation($participant);

        // The participant's real primary key reaches the store, even when it is not named "id"...
        $this->assertSame('uuid-123', $store->receivedId);
    }

    public function testItStartsAConversationForTheParticipantWhenNoConversationIdIsGiven(): void
    {
        $participant = new class {
            public int $id = 7;
        };

        $agent = (new RememberingAssistantAgent)->continueOrStart(null, as: $participant);

        $this->assertNull($agent->currentConversation());
        $this->assertSame($participant, $agent->conversationParticipant());
    }

    public function testItContinuesTheGivenConversationForTheParticipant(): void
    {
        $participant = new class {
            public int $id = 7;
        };

        $agent = (new RememberingAssistantAgent)->continueOrStart('conversation-1', as: $participant);

        $this->assertSame('conversation-1', $agent->currentConversation());
        $this->assertSame($participant, $agent->conversationParticipant());
    }

    public function testItStartsAConversationOnTheFirstTurnAndContinuesItOnTheNext(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $participant = new class {
            public int $id = 7;
        };

        RememberingAssistantAgent::fake(['First answer.', 'Second answer.']);

        // A route passes whatever the request carried, which is nothing on the first turn...
        $first = (new RememberingAssistantAgent)
            ->continueOrStart(null, as: $participant)
            ->prompt('Hello.');

        $second = (new RememberingAssistantAgent)
            ->continueOrStart($first->conversationId, as: $participant)
            ->prompt('Tell me more.');

        $this->assertNotNull($first->conversationId);
        $this->assertSame($first->conversationId, $second->conversationId);
        $this->assertSame(1, DB::table('agent_conversations')->count());
        $this->assertSame(4, DB::table('agent_conversation_messages')->count());
    }
}
