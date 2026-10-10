<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi\Concerns;

use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Support\Collection;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;
    use JoinsReasoning;

    /**
     * Validate the OpenAI response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $data['error']['type'] ?? $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse the OpenAI response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $output = $data['output'] ?? [];
        $text = $this->extractText($output);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCallsWithReasoning($output),
            finishReason: $this->extractFinishReason($data),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $data['model'] ?? '', $this->extractCitations($output)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            continuationToken: $data['id'] ?? '',
            replayBlocks: $this->extractReplayBlocks($output),
            reasoning: $this->extractReasoning($output),
            providerToolCalls: $this->extractProviderToolCalls($output),
        );
    }

    /**
     * Extract the text content from the output array.
     */
    protected function extractText(array $output): string
    {
        $text = '';

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $text .= $content['text'] ?? '';
                }
            }
        }

        return $text;
    }

    /**
     * Extract the reasoning text from the output array.
     */
    protected function extractReasoning(array $output): string
    {
        return static::joinReasoning(
            (new Collection($output))
                ->where('type', 'reasoning')
                ->flatMap(fn (array $item): array => [
                    (new Collection($item['summary'] ?? []))->pluck('text')->implode(''),
                    (new Collection($item['content'] ?? []))->pluck('text')->implode(''),
                ])
        );
    }

    /**
     * Extract citations from the output array.
     */
    protected function extractCitations(array $output): Collection
    {
        $citations = new Collection;

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') !== 'url_citation') {
                        continue;
                    }

                    $citations->push(new UrlCitation(
                        $annotation['url'] ?? '',
                        $annotation['title'] ?? null,
                        isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                        isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                    ));
                }
            }
        }

        return $citations->values();
    }

    /**
     * Extract the ordered response output for full-history replay.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractReplayBlocks(array $output): array
    {
        return array_values(array_filter($output, 'is_array'));
    }

    /**
     * Extract the provider-hosted tool items from the output array.
     *
     * @return array<int, ProviderToolCall>
     */
    protected function extractProviderToolCalls(array $output): array
    {
        return array_values(array_map(
            fn (array $item): ProviderToolCall => new ProviderToolCall($item['id'] ?? '', $item['type'], $item),
            array_filter($output, fn (mixed $item): bool => is_array($item)
                && ($item['type'] ?? '') !== 'function_call'
                && str_ends_with((string) ($item['type'] ?? ''), '_call')),
        ));
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];

        return new TextUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $usage['input_tokens_details']['cached_tokens'] ?? null,
            cacheWriteInputTokens: $usage['input_tokens_details']['cache_write_tokens'] ?? null,
            reasoningTokens: $usage['output_tokens_details']['reasoning_tokens'] ?? null,
        );
    }

    /**
     * Extract usage data from an image generation response.
     */
    protected function extractImageUsage(array $data): ImageUsage
    {
        $usage = $data['usage'] ?? [];

        return new ImageUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $usage['input_tokens_details']['cached_tokens'] ?? null,
            imageInputTokens: $usage['input_tokens_details']['image_tokens'] ?? null,
            imageOutputTokens: $usage['output_tokens_details']['image_tokens'] ?? null,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        // Completed output items do not make an interrupted response complete.
        if (($data['status'] ?? null) === 'incomplete') {
            return ($data['incomplete_details']['reason'] ?? null) === 'content_filter'
                ? FinishReason::ContentFilter
                : FinishReason::Length;
        }

        $lastOutput = last($data['output'] ?? []);
        $status = $lastOutput['status'] ?? $data['status'] ?? '';
        $type = $lastOutput['type'] ?? '';

        return match ($status) {
            'incomplete' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string) $type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }

    /**
     * Map tool calls with their associated reasoning blocks.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCallsWithReasoning(array $output): array
    {
        $toolCalls = [];
        $latestReasoning = null;

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = $item;

                continue;
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_decode($item['arguments'] ?? '{}', true) ?? [],
                    $item['call_id'] ?? null,
                    $latestReasoning ? ($latestReasoning['id'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['summary'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['encrypted_content'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
    }
}
