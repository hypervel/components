<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi\Concerns;

use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\MessageRole;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Support\Arr;

trait MapsMessages
{
    /**
     * Map the given Hypervel messages to OpenAI Responses API input format.
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions, Provider $provider): array
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
                MessageRole::User => $this->mapUserMessage($message, $input, $provider),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input, $provider),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to OpenAI format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input, Provider $provider): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments, $provider));
        }

        $input[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to OpenAI format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input, Provider $provider): void
    {
        if ($message instanceof AssistantMessage && filled($message->replayBlocks)) {
            $blocks = $this->isStateless($provider)
                ? $this->withoutStoredOnlyItems($message->replayBlocks)
                : $message->replayBlocks;

            foreach ($blocks as $block) {
                $input[] = $block;
            }

            return;
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            $reasoningBlocks = $message->toolCalls
                ->whereNotNull('reasoningId')
                ->unique('reasoningId')
                ->map(fn (ToolCall $toolCall): array => Arr::whereNotNull([
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                    'encrypted_content' => $toolCall->reasoningEncryptedContent,
                ]))
                ->values()
                ->all();

            foreach ($reasoningBlocks as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach ($message->toolCalls->where('reasoningId', $reasoningBlock['id']) as $toolCall) {
                    $input[] = $this->functionCallItem($toolCall);
                }
            }

            foreach ($message->toolCalls->whereNull('reasoningId') as $toolCall) {
                $input[] = $this->functionCallItem($toolCall);
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
     * Remove file_search_call items, which the API resolves by id and so rejects when store is false.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    protected function withoutStoredOnlyItems(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            fn (array $block): bool => ($block['type'] ?? null) !== 'file_search_call',
        ));
    }

    /**
     * Map a tool call to a function_call input item, keeping the item id only when OpenAI issued it and its reasoning survived.
     *
     * @return array<string, mixed>
     */
    protected function functionCallItem(ToolCall $toolCall): array
    {
        // A replayed call whose reasoning was dropped cannot carry its item id, as the API rejects an fc_ item with no reasoning item before it...
        return Arr::whereNotNull([
            'id' => $toolCall->reasoningId !== null && str_starts_with($toolCall->id, 'fc_') ? $toolCall->id : null,
            'call_id' => $toolCall->resultId,
            'type' => 'function_call',
            'name' => $toolCall->name,
            'arguments' => json_encode((object) $toolCall->arguments),
        ]);
    }

    /**
     * Map a tool result message to OpenAI format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId,
                'output' => $toolResult->text(),
            ];
        }
    }
}
