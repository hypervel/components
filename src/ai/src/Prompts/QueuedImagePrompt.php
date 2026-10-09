<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class QueuedImagePrompt
{
    public readonly Collection $attachments;

    /**
     * Create a queued image prompt.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly string $prompt,
        Collection|array $attachments,
        public readonly ?string $size,
        public readonly ?string $quality,
        public readonly Provider|Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly ?int $timeout = null,
        public readonly array $providerOptions = [],
    ) {
        $this->attachments = Collection::make($attachments);
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Determine if the image generation is square.
     */
    public function isSquare(): bool
    {
        return $this->size === '1:1';
    }

    /**
     * Determine if the image generation is landscape.
     */
    public function isLandscape(): bool
    {
        return $this->size === '3:2';
    }

    /**
     * Determine if the image generation is portrait.
     */
    public function isPortrait(): bool
    {
        return $this->size === '2:3';
    }
}
