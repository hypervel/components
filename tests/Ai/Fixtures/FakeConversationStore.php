<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Support\Collection;
use Throwable;

class FakeConversationStore implements ConversationStore
{
    /**
     * Return no previous conversation.
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return null;
    }

    /**
     * Return the supplied or fake conversation ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $id ?? 'conversation-123';
    }

    /**
     * Return a fake user message ID.
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        return 'user-message-123';
    }

    /**
     * Return a fake assistant message ID.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        return 'assistant-message-123';
    }

    /**
     * Return an empty conversation history.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return new Collection;
    }

    /**
     * Accept approval results without persistence.
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
    }
}
