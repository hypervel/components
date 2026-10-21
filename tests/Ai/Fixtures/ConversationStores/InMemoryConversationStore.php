<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\ConversationStores;

use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Throwable;

class InMemoryConversationStore implements ConversationStore
{
    public array $conversations = [];

    public array $messages = [];

    /**
     * Get the latest conversation ID for the participant.
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return collect($this->conversations)
            ->filter(fn (array $conversation): bool => $conversation['participant_type'] === $participantType
                && $conversation['participant_id'] == $participantId)
            ->keys()
            ->last();
    }

    /**
     * Store a conversation.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();

        $this->conversations[$id] = ['participant_type' => $participantType, 'participant_id' => $participantId, 'title' => $title];

        return $id;
    }

    /**
     * Store a user message.
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $message->content,
        ];

        return $id;
    }

    /**
     * Store an assistant message.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $response->text,
        ];

        return $id;
    }

    /**
     * Get the latest messages in a conversation.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collect($this->messages)
            ->where('conversation_id', $conversationId)
            ->take($limit)
            ->values();
    }

    /**
     * Store approval results.
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
    }
}
