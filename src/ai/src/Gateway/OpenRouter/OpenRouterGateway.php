<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter;

use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Contracts\Providers\SupportsWebFetch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\Concerns\WrapsPcmAudio;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\CreatesOpenRouterClient;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\HandlesTextStreaming;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\MapsMessages;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\ParsesTextResponses;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class OpenRouterGateway implements Gateway, RerankingGateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesOpenRouterClient;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsMessages;
    use ParsesTextResponses;
    use HandlesFailoverErrors;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;
    use WrapsPcmAudio;

    /**
     * Create an OpenRouter gateway instance.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Map a provider tool to an OpenRouter tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('OpenRouter does not support [' . class_basename($tool) . '] provider tools.'),
        };
    }

    /**
     * Map a web fetch tool to an OpenRouter server tool definition.
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web fetch.');
        }

        return [
            'type' => 'openrouter:web_fetch',
            ...$provider->webFetchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an OpenRouter server tool definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web search.');
        }

        return [
            'type' => 'openrouter:web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }

    /**
     * Generate images from the given prompt.
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        /** @var ImageProvider&Provider $provider */
        $imageConfig = Arr::pull($providerOptions, 'image_config', []);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post('images', array_merge($providerOptions, array_filter([
                    'aspect_ratio' => $imageConfig['aspect_ratio'] ?? null,
                    'resolution' => $imageConfig['image_size'] ?? null,
                ]), array_filter([
                    'model' => $model,
                    'prompt' => $prompt,
                    ...$provider->defaultImageOptions($size, $quality),
                    'input_references' => $this->mapAttachments(collect($attachments)) ?: null,
                ], fn (mixed $value): bool => $value !== null)))
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                $image['media_type'] ?? 'image/png',
            )),
            new ImageUsage($data['usage']['prompt_tokens'] ?? 0, $data['usage']['completion_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate audio from the given text.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        /** @var AudioProvider&Provider $provider */
        $format = $this->audioResponseFormat($model);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_merge(['speed' => 1.0], $providerOptions, Arr::whereNotNull([
                'model' => $model,
                'input' => $text,
                'voice' => $this->resolveVoice($model, $voice),
                'response_format' => $format,
                'instructions' => $instructions,
            ]))),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Usage,
            new Meta($provider->name(), $model),
            $this->audioResponseMimeType($format),
        );
    }

    /**
     * Resolve the alias voice for the given model.
     */
    protected function resolveVoice(string $model, string $voice): string
    {
        if ($this->isGeminiTtsModel($model)) {
            return match ($voice) {
                'default-male' => 'Puck',
                'default-female' => 'Kore',
                default => $voice,
            };
        }

        return match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };
    }

    /**
     * Resolve the response_format the model accepts. Gemini TTS only accepts pcm.
     */
    protected function audioResponseFormat(string $model): string
    {
        return $this->isGeminiTtsModel($model) ? 'pcm' : 'mp3';
    }

    /**
     * Map a response_format value to the HTTP audio MIME type.
     */
    protected function audioResponseMimeType(string $format): string
    {
        return match ($format) {
            'mp3' => 'audio/mpeg',
            'pcm' => 'audio/pcm',
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    /**
     * Determine whether the model uses Gemini text-to-speech.
     */
    protected function isGeminiTtsModel(string $model): bool
    {
        return str_contains($model, 'gemini') && str_contains($model, 'tts');
    }

    /**
     * Generate text from the given audio.
     *
     * @param array<string, mixed> $providerOptions
     *
     * @throws LogicException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        /** @var Provider&TranscriptionProvider $provider */
        if ($diarize) {
            throw new LogicException(
                'OpenRouter does not support diarized transcription. Use the OpenAI, ElevenLabs, Mistral, or Gemini provider for diarization.'
            );
        }

        $mimeType = $audio->mimeType();
        $content = $audio->content();

        if ($mimeType === 'audio/pcm') {
            $content = $this->pcmToWav($content);
            $mimeType = 'audio/wav';
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                'model' => $model,
                'input_audio' => [
                    'data' => base64_encode($content),
                    'format' => $this->audioFormat($mimeType),
                ],
                'language' => $language,
            ]))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect(),
            new TranscriptionUsage(
                inputTokens: Arr::get($data, 'usage.input_tokens', 0),
                outputTokens: Arr::get($data, 'usage.output_tokens', 0),
                audioSeconds: Arr::get($data, 'usage.seconds'),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map an audio MIME type to OpenRouter's input_audio.format value.
     */
    protected function audioFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/aiff', 'audio/x-aiff' => 'aiff',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            default => throw new InvalidArgumentException(
                "Unsupported audio MIME type [{$mimeType}] for OpenRouter. Supported types: audio/wav, audio/mp3, audio/mpeg, audio/flac, audio/m4a, audio/mp4, audio/ogg, audio/webm, audio/aac, audio/aiff."
            ),
        };
    }

    /**
     * Generate embeddings for the given inputs.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        /** @var EmbeddingProvider&Provider $provider */
        $body = array_merge($providerOptions, [
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions,
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->sortBy('index')->pluck('embedding')->all(),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents by relevance to a query.
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse {
        /** @var Provider&RerankingProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('rerank', array_merge($providerOptions, Arr::whereNotNull([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ]))),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        $results = (new Collection($data['results']))->map(fn (array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->all();

        return new RerankingResponse(
            $results,
            new RerankingUsage(
                inputTokens: $data['usage']['total_tokens'] ?? 0,
                searchUnits: $data['usage']['search_units'] ?? null,
            ),
            new Meta($provider->name(), $model),
        );
    }
}
