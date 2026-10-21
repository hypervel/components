<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Gateway\VoyageAi\VoyageAiGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasRerankingGateway;
use Hypervel\Ai\Providers\Concerns\Reranks;
use Hypervel\Contracts\Events\Dispatcher;
use InvalidArgumentException;

class VoyageAiProvider extends Provider implements EmbeddingProvider, RerankingProvider
{
    use GeneratesEmbeddings;
    use HasEmbeddingGateway;
    use HasRerankingGateway;
    use Reranks;

    /**
     * Create a Voyage AI provider instance.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'voyage-4';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Validate embeddings inputs against Voyage AI's supported media types.
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            if ($input instanceof Image && ! $input instanceof ProviderImage) {
                if ($this->isVoyageMultimodalModel($model)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI image embeddings. Use [voyage-multimodal-3.5] or [voyage-multimodal-3]."
                );
            }

            if ($input instanceof Video) {
                if ($model === 'voyage-multimodal-3.5') {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI video embeddings. Use [voyage-multimodal-3.5]."
                );
            }

            throw new InvalidArgumentException(
                'Provider [voyageai] only supports text, image, and video embeddings inputs.'
            );
        }
    }

    /**
     * Determine if the given model supports Voyage AI multimodal embeddings.
     */
    protected function isVoyageMultimodalModel(string $model): bool
    {
        return in_array($model, ['voyage-multimodal-3.5', 'voyage-multimodal-3'], true);
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new VoyageAiGateway;
    }

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'rerank-3';
    }

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new VoyageAiGateway;
    }
}
