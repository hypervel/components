<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible;

use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesIndexedEmbeddings;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\CreatesOpenAiCompatibleClient;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\HandlesTextStreaming;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\ParsesTextResponses;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Arr;
use InvalidArgumentException;

class OpenAiCompatibleGateway implements EmbeddingGateway, StepTextGateway, TranscriptionGateway
{
    use BuildsTextRequests;
    use CreatesOpenAiCompatibleClient;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesTextResponses;
    use PerformsChatCompletionSteps;
    use HandlesFailoverErrors;
    use ParsesIndexedEmbeddings;
    use ParsesServerSentEvents;
    use ResolvesAudioFilenames;

    /**
     * Create an OpenAI-compatible gateway instance.
     */
    public function __construct(protected Dispatcher $events)
    {
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
        if (($providerOptions['encoding_format'] ?? 'float') !== 'float') {
            throw new InvalidArgumentException('This openai-compatible provider only supports float embedding responses.');
        }

        $body = array_merge($providerOptions, array_filter([
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            $this->parseEmbeddings($data, count($inputs)),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Parse the embedding vectors on an OpenAI-compatible response.
     *
     * @return array<int, array<int, float>>
     */
    protected function parseEmbeddings(array $data, int $expectedCount): array
    {
        return array_map(static function (mixed $embedding): array {
            if (! is_array($embedding) || $embedding === [] || array_filter($embedding, 'is_numeric') !== $embedding) {
                throw new AiException('OpenAI-compatible Error: [invalid_response] The response contained an invalid embedding vector.');
            }

            return array_map(floatval(...), $embedding);
        }, $this->parseIndexedEmbeddings($data['data'] ?? [], $expectedCount, 'OpenAI-compatible'));
    }

    /**
     * Generate a transcription for the given audio.
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge(array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]), $providerOptions)),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new TranscriptionUsage(
                inputTokens: Arr::get($data, 'usage.input_tokens') ?? Arr::get($data, 'usage.prompt_tokens', 0),
                outputTokens: Arr::get($data, 'usage.output_tokens') ?? Arr::get($data, 'usage.completion_tokens', 0),
                audioSeconds: Arr::get($data, 'usage.seconds') ?? Arr::get($data, 'duration'),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return $provider->additionalConfiguration()['stream_options'] ?? null;
    }
}
