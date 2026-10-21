<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Usage implements Arrayable, JsonSerializable
{
    /**
     * Create token usage data.
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {
    }

    /**
     * Get the total number of input and output tokens.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
