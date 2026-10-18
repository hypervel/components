<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class ImagePrompt
{
    public readonly Collection $attachments;

    /**
     * Create an image prompt.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly string $prompt,
        Collection|array $attachments,
        public readonly ?string $size,
        public readonly ?string $quality,
        public readonly ImageProvider $provider,
        public readonly string $model,
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
