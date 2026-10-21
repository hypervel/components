<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Groq\Concerns;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the Groq response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Groq Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Groq error.',
            ));
        }
    }

    /**
     * Parse a single Groq response into a StepResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($choice);

        $mappedToolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            reasoning: (string) ($message['reasoning'] ?? ''),
        );
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];

        return new TextUsage(
            inputTokens: $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $usage['prompt_tokens_details']['cached_tokens'] ?? null,
            reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
