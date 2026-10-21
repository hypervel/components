<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Support\Str;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the Ollama response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Ollama Error: %s',
                $data['error'] ?? 'Unknown Ollama error.',
            ));
        }
    }

    /**
     * Parse the Ollama response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $message = $data['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];

        $mappedToolCalls = array_map(function (array $toolCall): ToolCall {
            $id = $toolCall['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                id: $id,
                name: $toolCall['function']['name'] ?? '',
                arguments: $this->parseToolArguments($toolCall['function']['arguments'] ?? []),
                resultId: $toolCall['id'] ?? null,
            );
        }, $rawToolCalls);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: filled($mappedToolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason($data),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            reasoning: (string) ($message['thinking'] ?? ''),
        );
    }

    /**
     * Parse tool call arguments, handling both array and JSON string formats.
     */
    protected function parseToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return json_decode($arguments ?? '{}', true) ?? [];
    }

    /**
     * Extract usage data from the Ollama response.
     */
    protected function extractUsage(array $data): TextUsage
    {
        return new TextUsage(
            inputTokens: $data['prompt_eval_count'] ?? 0,
            outputTokens: $data['eval_count'] ?? 0,
            cacheReadInputTokens: $data['prompt_eval_cached_count'] ?? null,
        );
    }

    /**
     * Extract and map the finish reason from the Ollama response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['done_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
