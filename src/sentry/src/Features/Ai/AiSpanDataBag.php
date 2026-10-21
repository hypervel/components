<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\Usage;

class AiSpanDataBag
{
    /**
     * Create a span data bag.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    /**
     * Set a nonempty value.
     */
    public function set(string $key, mixed $value): void
    {
        if ($value === null || $value === '' || $value === [] || $value === '[]' || $value === '{}') {
            return;
        }
        $this->data[$key] = $value;
    }

    /**
     * Set a value only when it has not been recorded.
     */
    public function setIfNotExists(string $key, mixed $value): void
    {
        if (! isset($this->data[$key])) {
            $this->set($key, $value);
        }
    }

    /**
     * Set a reported nonzero count.
     */
    public function setNonZero(string $key, ?int $value): void
    {
        if ($value !== null && $value !== 0) {
            $this->data[$key] = $value;
        }
    }

    /**
     * Add the reported token usage.
     */
    public function setTokenUsage(?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }
        $this->setNonZero('gen_ai.usage.input_tokens', $usage->inputTokens);
        $this->setNonZero('gen_ai.usage.output_tokens', $usage->outputTokens);
        $this->setNonZero('gen_ai.usage.total_tokens', $usage->totalTokens());

        if ($usage instanceof TextUsage) {
            $this->setNonZero('gen_ai.usage.input_tokens.cached', $usage->cacheReadInputTokens);
            $this->setNonZero('gen_ai.usage.input_tokens.cache_write', $usage->cacheWriteInputTokens);
            $this->setNonZero('gen_ai.usage.output_tokens.reasoning', $usage->reasoningTokens);
        }
    }

    /**
     * Return a recorded value.
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Return the recorded span data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
