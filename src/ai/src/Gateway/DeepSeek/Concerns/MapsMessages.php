<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\DeepSeek\Concerns;

use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\MessageRole;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Hypervel messages to Chat Completions messages format.
     */
    protected function mapMessagesToChat(array $messages, ?string $instructions = null): array
    {
        $chatMessages = [];

        if (filled($instructions)) {
            $chatMessages[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $chatMessages),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $chatMessages),
            };
        }

        return $chatMessages;
    }

    /**
     * Map a user message to Chat Completions format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ],
        ];
    }

    /**
     * Map an assistant message to Chat Completions format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $msg = ['role' => 'assistant'];

        if (filled($message->content)) {
            $msg['content'] = $message->content;
        }

        if (! $message instanceof AssistantMessage) {
            $chatMessages[] = $msg;

            return;
        }

        if ($message->toolCalls->isNotEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn (ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall)
            )->all();

            $msg['reasoning_content'] = collect($message->replayBlocks)->firstWhere('type', 'reasoning')['reasoning_content'] ?? '';
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Chat Completions format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'content' => $toolResult->text(),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Chat Completions array format.
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode((object) $toolCall->arguments),
            ],
        ];
    }
}
