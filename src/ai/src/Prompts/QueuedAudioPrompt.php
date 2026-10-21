<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Str;

class QueuedAudioPrompt
{
    /**
     * Create a queued audio prompt.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly ?string $instructions,
        public readonly Provider|Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the text contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->text, $string);
    }

    /**
     * Determine if the voice is male.
     */
    public function isMale(): bool
    {
        return $this->voice === 'default-male';
    }

    /**
     * Determine if the voice is female.
     */
    public function isFemale(): bool
    {
        return $this->voice === 'default-female';
    }
}
