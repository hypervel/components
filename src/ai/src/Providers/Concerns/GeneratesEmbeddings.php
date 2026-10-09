<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Support\Str;
use InvalidArgumentException;

trait GeneratesEmbeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     * @param array<string, mixed> $providerOptions
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        if (! is_null($model) && is_null($dimensions)) {
            if (! $this->supportsNativeEmbeddingDimensions()) {
                throw new InvalidArgumentException('Dimensions must be provided when model is specified.');
            }

            $dimensions = 0;
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultEmbeddingsModel();
        $dimensions ??= $this->defaultEmbeddingsDimensions();

        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $this, $model, $timeout, $providerOptions);

        if (Ai::embeddingsAreFaked()) {
            Ai::recordEmbeddingsGeneration($prompt);
        } else {
            $this->validateEmbeddingInputs($inputs, $model);
        }

        if ($this->events->hasListeners(GeneratingEmbeddings::class)) {
            $this->events->dispatch(new GeneratingEmbeddings(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->embeddingGateway()->generateEmbeddings(
            $this,
            $model,
            $inputs,
            $dimensions,
            $timeout,
            $providerOptions,
        ), function (EmbeddingsResponse $response) use ($invocationId, $model, $prompt): void {
            if ($this->events->hasListeners(EmbeddingsGenerated::class)) {
                $this->events->dispatch(new EmbeddingsGenerated(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response,
                ));
            }
        });
    }

    /**
     * Determine if the provider may omit dimensions and use a model's native embedding dimensions.
     */
    protected function supportsNativeEmbeddingDimensions(): bool
    {
        return false;
    }

    /**
     * Validate embeddings inputs against the provider's supported media types.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            throw new InvalidArgumentException(
                'Provider [' . $this->driver() . '] only supports text embeddings inputs.'
            );
        }
    }
}
