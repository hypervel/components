<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Support\Collection;
use Throwable;

interface ConversationStore
{
    /**
     * Get the participant's most recent conversation ID with the given agent.
     *
     * @param class-string<Agent> $agent
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string;

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string;

    /**
     * Store a new user message for the given conversation and return its ID.
     *
     * @param class-string<Agent> $agent
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string;

    /**
     * Store the assistant turn, folding a resume into the row it paused on, or null when nothing was stored.
     *
     * @param null|Throwable $exception the error the run died with, when it did not finish
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param array<int, ToolResult> $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void;
}
