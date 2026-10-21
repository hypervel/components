<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Responses\EmbeddingsResponse;

interface EmbeddingProvider extends Provider
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     * @param array<string, mixed> $providerOptions
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse;

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway;

    /**
     * Set the provider's embedding gateway.
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway): self;

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string;

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int;
}
