<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Countable;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Support\Str;

class EmbeddingsPrompt implements Countable
{
    /**
     * Create an embeddings prompt.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly array $inputs,
        public readonly int $dimensions,
        public readonly EmbeddingProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if any of the inputs contain the given string.
     */
    public function contains(string $string): bool
    {
        return array_any($this->inputs, fn (mixed $input): bool => is_string($input) && Str::contains($input, $string));
    }

    /**
     * Get the number of inputs in the prompt.
     */
    public function count(): int
    {
        return count($this->inputs);
    }
}
