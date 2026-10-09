<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use RuntimeException;

class FakeEmbeddingGateway implements EmbeddingGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    /**
     * Create an embedding gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     * @param array<string, mixed> $providerOptions
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt
    ): EmbeddingsResponse {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt
    ): EmbeddingsResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted embedding generation without a fake response.');
            }

            $response = $this->generateFakeEmbeddings(
                count($prompt->inputs),
                $prompt->dimensions
            );
        }

        if (is_array($response) && ($response === [] || is_array($response[0] ?? null))) {
            return new EmbeddingsResponse(
                $response,
                new Usage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate fake embedding vectors.
     *
     * @return array<int, array<float>>
     */
    protected function generateFakeEmbeddings(int $count, int $dimensions): array
    {
        if ($dimensions <= 0) {
            throw new RuntimeException('Unable to generate fake embeddings without positive dimensions. Configure embedding dimensions or provide a fake response.');
        }

        return array_map(
            fn (): array => Embeddings::fakeEmbedding($dimensions),
            range(1, $count)
        );
    }

    /**
     * Indicate that an exception should be thrown if any embeddings generation is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayEmbeddings(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
