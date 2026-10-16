<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsFileSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebFetch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Gemini\GeminiFileGateway;
use Hypervel\Ai\Gateway\Gemini\GeminiStoreGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesAudio;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesImages;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasAudioGateway;
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
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Support\Collection;
use InvalidArgumentException;

class GeminiProvider extends Provider implements AudioProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsCodeExecution, SupportsFileSearch, SupportsWebFetch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use GeneratesAudio;
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasAudioGateway;
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
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'file_search_store_names' => $search->ids(),
            'metadata_filter' => $search->filters === []
                ? null
                : $this->formatMetadataFilter($search->filters),
        ]);
    }

    /**
     * Format the file search metadata filter for Gemini's filter expression syntax.
     *
     * @param array<int, array{type: 'eq'|'in'|'ne'|'nin', key: string, value: mixed}> $filters
     */
    protected function formatMetadataFilter(array $filters): string
    {
        return (new Collection($filters))->map(fn (array $filter): string => match ($filter['type']) {
            'eq' => $this->formatMetadataCondition($filter['key'], '=', $filter['value']),
            'ne' => $this->formatMetadataCondition($filter['key'], '!=', $filter['value']),
            'in' => '(' . (new Collection($filter['value']))->map(
                fn (mixed $value): string => $this->formatMetadataCondition($filter['key'], '=', $value)
            )->implode(' OR ') . ')',
            'nin' => '(' . (new Collection($filter['value']))->map(
                fn (mixed $value): string => $this->formatMetadataCondition($filter['key'], '!=', $value)
            )->implode(' AND ') . ')',
        })->implode(' AND ');
    }

    /**
     * Format a condition with an escaped metadata value.
     */
    protected function formatMetadataCondition(string $key, string $operator, mixed $value): string
    {
        return $key . $operator . (is_numeric($value)
            ? (string) $value
            : '"' . addcslashes((string) $value, '\"') . '"');
    }

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Gemini);
    }

    /**
     * Get the web fetch tool options for the provider.
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return [];
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return [];
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'gemini-3.8-flash';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'gemini-3.1-flash-lite';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'gemini-3.8-flash';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'gemini-3.1-flash-image';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'image_size' => match ($quality) {
                'low', '1K' => '1K',
                'medium', '2K' => '2K',
                'high', '4K' => '4K',
                default => '1K',
            },
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                null => null,
                default => $size,
            },
        ]);
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'gemini-3.8-flash-lite-tts';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'gemini-3.5-transcribe';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'gemini-embedding-2';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 3072;
    }

    /**
     * Validate embeddings inputs against Gemini's supported media types.
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            if (! $this->isGeminiMultimodalEmbeddingModel($model)) {
                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Gemini multimodal embeddings. Use [gemini-embedding-2] or [gemini-embedding-2-preview]."
                );
            }

            return;
        }
    }

    /**
     * Determine if the given model supports Gemini multimodal embeddings.
     */
    protected function isGeminiMultimodalEmbeddingModel(string $model): bool
    {
        return in_array($model, ['gemini-embedding-2', 'gemini-embedding-2-preview'], true);
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new GeminiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new GeminiStoreGateway;
    }
}
