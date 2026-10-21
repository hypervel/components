<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\Bedrock\BedrockCredentials;
use Hypervel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Hypervel\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Hypervel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesImages;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasImageGateway;
use Hypervel\Ai\Providers\Concerns\HasRerankingGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\Reranks;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Contracts\Events\Dispatcher;
use Override;

class BedrockProvider extends Provider implements EmbeddingProvider, ImageProvider, RerankingProvider, TextProvider
{
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use HasEmbeddingGateway;
    use HasImageGateway;
    use HasRerankingGateway;
    use HasTextGateway;
    use Reranks;
    use StreamsText;

    protected BedrockCredentials $bedrockCredentials;

    /**
     * Create a Bedrock provider instance.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events
    ) {
        $this->bedrockCredentials = new BedrockCredentials;
    }

    /**
     * Get the credential holder shared by this provider's gateways.
     *
     * @internal
     */
    public function bedrockCredentials(): BedrockCredentials
    {
        return $this->bedrockCredentials;
    }

    /**
     * Get the credentials for the underlying AI provider.
     */
    #[Override]
    public function providerCredentials(): array
    {
        return array_filter([
            'access_key_id' => $this->config['access_key_id'] ?? null,
            'secret_access_key' => $this->config['secret_access_key'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
            'key' => $this->config['key'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    #[Override]
    public function additionalConfiguration(): array
    {
        return [
            'region' => $this->config['region'] ?? 'us-east-1',
            'use_default_credential_provider' => $this->config['use_default_credential_provider'] ?? true,
            'headers' => $this->config['headers'] ?? [],
            'assume_role' => [
                'arn' => $this->config['assume_role']['arn'] ?? null,
                'session_name' => $this->config['assume_role']['session_name'] ?? null,
                'duration_seconds' => $this->config['assume_role']['duration_seconds'] ?? null,
                'external_id' => $this->config['assume_role']['external_id'] ?? null,
            ],
        ];
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'global.anthropic.claude-sonnet-5-5';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'global.anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'global.anthropic.claude-opus-5-5';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'amazon.titan-embed-text-v2:0';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'amazon.nova-canvas-v1:0';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return [
            'quality' => match ($quality) {
                'high', 'premium' => 'premium',
                'low', 'medium', 'standard', null => 'standard',
                default => $quality,
            },
            'size' => match ($size) {
                '2:3' => '768x1152',
                '3:2' => '1152x768',
                '1:1', null => '1024x1024',
                default => $size,
            },
        ];
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new BedrockTextGateway;
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new BedrockTextGateway;
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new BedrockImageGateway;
    }

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'cohere.rerank-v3-5:0';
    }

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new BedrockRerankingGateway;
    }
}
