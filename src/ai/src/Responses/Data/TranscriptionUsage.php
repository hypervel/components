<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

readonly class TranscriptionUsage extends TextUsage
{
    /**
     * Create transcription usage data.
     *
     * @param null|float $audioSeconds duration of the transcribed audio in seconds, or null when unreported
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?float $audioSeconds = null,
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
    }

    /**
     * Create a transcription usage instance from the given usage.
     */
    public static function from(TextUsage $usage, ?float $audioSeconds = null): self
    {
        return new self(
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheReadInputTokens,
            $usage->cacheWriteInputTokens,
            $usage->reasoningTokens,
            $audioSeconds,
        );
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'audio_seconds' => $this->audioSeconds,
        ];
    }
}
