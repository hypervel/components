<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsFileSearch;
use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\AzureOpenAi\AzureOpenAiFileGateway;
use Hypervel\Ai\Gateway\AzureOpenAi\AzureOpenAiGateway;
use Hypervel\Ai\Gateway\AzureOpenAi\AzureOpenAiStoreGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesImages;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasFileGateway;
use Hypervel\Ai\Providers\Concerns\HasImageGateway;
use Hypervel\Ai\Providers\Concerns\HasStoreGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\ManagesFiles;
use Hypervel\Ai\Providers\Concerns\ManagesStores;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Override;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsCodeExecution, SupportsFileSearch, SupportsToolSearch, SupportsWebSearch, TextProvider
{
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use HasEmbeddingGateway;
    use HasFileGateway;
    use HasImageGateway;
    use HasStoreGateway;
    use HasTextGateway;
    use ManagesFiles;
    use ManagesStores;
    use StreamsText;

    protected ?AzureOpenAiGateway $azureGateway = null;

    /**
     * Create an Azure OpenAI provider instance.
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
    }

    /**
     * Get the shared Azure OpenAI gateway instance.
     */
    protected function azureGateway(): AzureOpenAiGateway
    {
        return $this->azureGateway ??= new AzureOpenAiGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->azureGateway();
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->azureGateway();
    }

    /**
     * Get the name of the default (deployment name) text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-sol';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-luna';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-6-astra';
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->azureGateway();
    }

    /**
     * Get the name of the default image deployment.
     */
    public function defaultImageModel(): string
    {
        return $this->config['image_deployment'] ?? 'gpt-image-2.5-flare';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '1024x1536',
                '3:2' => '1536x1024',
                default => $size,
            },
            'quality' => $quality,
        ]);
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['embedding_deployment'] ?? 'text-embedding-3-small';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1536;
    }

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (filled($search->filters)) {
            throw new InvalidArgumentException('Azure OpenAI does not support file search metadata filters.');
        }

        return array_filter([
            'vector_store_ids' => $search->ids(),
        ]);
    }

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Azure) + [
            'container' => ['type' => 'auto'],
        ];
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::Azure);

        $filters = array_merge(
            filled($search->allowedDomains) ? ['allowed_domains' => $search->allowedDomains] : [],
            $options['filters'] ?? [],
        );

        unset($options['filters']);

        return array_filter([
            'filters' => filled($filters) ? $filters : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $options;
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    #[Override]
    public function additionalConfiguration(): array
    {
        return [
            'url' => rtrim($this->config['url'] ?? '', '/'),
            'api_version' => $this->config['api_version'] ?? '2025-04-01-preview',
            'store' => $this->config['store'] ?? true,
            'headers' => $this->config['headers'] ?? [],
        ];
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AzureOpenAiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new AzureOpenAiStoreGateway;
    }
}
