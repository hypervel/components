<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai\Concerns;

use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\MessageRole;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\Data\ToolCall;

trait MapsMessages
{
    /**
     * Map the given Hypervel messages to xAI Responses API input format.
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions = null): array
    {
        $input = [];

        if (filled($instructions)) {
            $input[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $input),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to xAI format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        $input[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to xAI format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $reasoningBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn (ToolCall $toolCall): array => [
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                ])
                ->values()
                ->all();

            foreach ($reasoningBlocks as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach ($message->toolCalls->where('reasoningId', $reasoningBlock['id']) as $toolCall) {
                    $input[] = [
                        'id' => $toolCall->id,
                        'call_id' => $toolCall->resultId ?? $toolCall->id,
                        'type' => 'function_call',
                        'name' => $toolCall->name,
                        'arguments' => json_encode((object) $toolCall->arguments),
                    ];
                }
            }

            foreach ($message->toolCalls->whereNull('reasoningId') as $toolCall) {
                $input[] = [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId ?? $toolCall->id,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode((object) $toolCall->arguments),
                ];
            }
        }

        if (filled($message->content)) {
            $input[] = [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $message->content,
                    ],
                ],
            ];
        }
    }

    /**
     * Map a tool result message to xAI format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId ?? $toolResult->id,
                'output' => $toolResult->text(),
            ];
        }
    }
}
