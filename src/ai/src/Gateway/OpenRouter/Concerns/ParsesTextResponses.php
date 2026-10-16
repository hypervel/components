<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter\Concerns;

use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\InsufficientCreditsException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Support\Collection;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;
    use JoinsReasoning;

    /**
     * Validate the OpenRouter response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        $choice = $data['choices'][0] ?? [];

        // OpenRouter can commit HTTP 200 before a provider fails, including buffered requests.
        if (! $data || isset($data['error']) || ($choice['finish_reason'] ?? null) === 'error') {
            $error = $data['error'] ?? $choice['error'] ?? [];
            $code = $error['code'] ?? null;
            $exception = match (true) {
                $code === 429 => RateLimitedException::class,
                $code === 402 => InsufficientCreditsException::class,
                in_array($code, $this->overloadedStatusCodes(), true) => ProviderOverloadedException::class,
                default => AiException::class,
            };

            throw new $exception(sprintf(
                'OpenRouter Error: [%s] %s',
                $error['type'] ?? $code ?? 'unknown',
                $error['message'] ?? 'Unknown OpenRouter error.',
            ), is_int($code) ? $code : 0);
        }
    }

    /**
     * Parse the OpenRouter response data into a single step response.
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
        $citations = $this->extractCitations($message);

        $toolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $message['tool_calls'] ?? []);

        $finishReason = $this->extractFinishReason($choice);

        // Gemini responses with encrypted reasoning can report stop alongside complete tool calls.
        if ($finishReason === FinishReason::Stop && $toolCalls !== []) {
            $finishReason = FinishReason::ToolCalls;
        }

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            replayBlocks: $this->extractReasoningDetails($message),
            reasoning: $this->extractReasoning($message),
        );
    }

    /**
     * Extract the reasoning text from an assistant message.
     */
    protected function extractReasoning(array $message): string
    {
        if (filled($message['reasoning'] ?? '')) {
            return (string) $message['reasoning'];
        }

        return static::joinReasoning(
            (new Collection($message['reasoning_details'] ?? []))
                ->map(fn (array $detail): string => (string) ($detail['text'] ?? $detail['summary'] ?? ''))
        );
    }

    /**
     * Extract the reasoning details to replay with the message's tool calls.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractReasoningDetails(array $message): array
    {
        return is_array($message['reasoning_details'] ?? null)
            ? array_values($message['reasoning_details'])
            : [];
    }

    /**
     * Extract URL citations from the message annotations array.
     */
    protected function extractCitations(array $message): Collection
    {
        $citations = new Collection;

        foreach ($message['annotations'] ?? [] as $annotation) {
            if (($annotation['type'] ?? '') === 'url_citation') {
                $urlCitation = $annotation['url_citation'] ?? [];

                $citations->push(new UrlCitation(
                    $urlCitation['url'] ?? '',
                    $urlCitation['title'] ?? null,
                    isset($urlCitation['start_index']) ? (int) $urlCitation['start_index'] : null,
                    isset($urlCitation['end_index']) ? (int) $urlCitation['end_index'] : null,
                ));
            }
        }

        return $citations->values();
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
            cacheWriteInputTokens: $usage['prompt_tokens_details']['cache_write_tokens'] ?? null,
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
