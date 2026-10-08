<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

readonly class ImageUsage extends TextUsage
{
    /**
     * Create image generation usage data.
     *
     * @param null|int $imageInputTokens subset of the input tokens billed for images, or null when unreported
     * @param null|int $imageOutputTokens subset of the output tokens billed for generated images, or null when unreported
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
        ];
    }
}
