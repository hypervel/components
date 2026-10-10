<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsFileSearch;
use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\OpenAi\OpenAiClassificationGateway;
use Hypervel\Ai\Gateway\OpenAi\OpenAiFileGateway;
use Hypervel\Ai\Gateway\OpenAi\OpenAiStoreGateway;
use Hypervel\Ai\Providers\Concerns\Classifies;
use Hypervel\Ai\Providers\Concerns\GeneratesAudio;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesImages;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasAudioGateway;
use Hypervel\Ai\Providers\Concerns\HasClassificationGateway;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasFileGateway;
use Hypervel\Ai\Providers\Concerns\HasImageGateway;
use Hypervel\Ai\Providers\Concerns\HasStoreGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Hypervel\Ai\Providers\Concerns\ManagesFiles;
use Hypervel\Ai\Providers\Concerns\ManagesStores;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Support\Collection;

class OpenAiProvider extends Provider implements AudioProvider, ClassificationProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsCodeExecution, SupportsFileSearch, SupportsToolSearch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use Classifies;
    use GeneratesAudio;
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasAudioGateway;
    use HasClassificationGateway;
    use HasEmbeddingGateway;
    use HasFileGateway;
    use HasImageGateway;
    use HasStoreGateway;
    use HasTextGateway;
    use HasTranscriptionGateway;
    use ManagesFiles;
    use ManagesStores;
    use StreamsText;

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::OpenAI) + [
            'container' => ['type' => 'auto'],
        ];
    }

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'vector_store_ids' => $search->ids(),
            'filters' => filled($search->filters) ? [
                'type' => 'and',
                'filters' => (new Collection($search->filters))->map(fn (array $filter): array => [
                    'type' => $filter['type'],
                    'key' => $filter['key'],
                    'value' => $filter['value'],
                ])->all(),
            ] : null,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::OpenAI);

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
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'gpt-6.1-sol';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'gpt-6-luna';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'gpt-6-astra';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'gpt-image-2.5-flare';
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
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'gpt-4o-mini-tts';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'gpt-4o-transcribe-diarize';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'text-embedding-3-small';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1536;
    }

    /**
     * Get the name of the default classification model.
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? 'gpt-6-luna';
    }

    /**
     * Determine if the provider can classify attachments alongside the state.
     */
    public function supportsClassificationAttachments(): bool
    {
        return true;
    }

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new OpenAiClassificationGateway;
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new OpenAiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new OpenAiStoreGateway;
    }
}
