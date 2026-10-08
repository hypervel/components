<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Responses\EmbeddingsResponse;

interface EmbeddingGateway
{
    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param array<int, Audio|Document|Image|string|Video> $inputs
     * @param array<string, mixed> $providerOptions
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse;
}
