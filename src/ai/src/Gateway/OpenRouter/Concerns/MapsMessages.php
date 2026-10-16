<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter\Concerns;

use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;

trait MapsMessages
{
    use MapsChatCompletionMessages {
        mapAssistantMessage as mapChatCompletionAssistantMessage;
    }

    /**
     * Map an assistant message, replaying its reasoning details with its tool calls.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $this->mapChatCompletionAssistantMessage($message, $chatMessages);

        // Some reasoning models require this field on tool follow-ups even when the list is empty.
        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $chatMessages[array_key_last($chatMessages)]['reasoning_details'] = $message->replayBlocks;
        }
    }
}
