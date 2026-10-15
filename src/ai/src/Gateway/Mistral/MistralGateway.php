<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Mistral;

use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\AudioGateway;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Events\Dispatcher;
use RuntimeException;

class MistralGateway implements AudioGateway, EmbeddingGateway, StepTextGateway, TranscriptionGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesMistralClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;
    use ResolvesAudioFilenames;

    /**
     * Create a Mistral gateway.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Build the request body for the current text generation step.
     *
     * @param Provider&TextProvider $provider
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Generate audio from the given text.
     *
     * @param AudioProvider&Provider $provider
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
        $voice = match ($voice) {
            'default-male' => 'en_paul_neutral',
            'default-female' => 'gb_jane_neutral',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_merge($providerOptions, [
                'model' => $model,
                'input' => $text,
                'voice_id' => $voice,
                'response_format' => 'mp3',
            ])),
        );

        $encodedAudio = $response->json('audio_data');

        if (! is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from Mistral API.');
        }

        return new AudioResponse(
            $encodedAudio,
            new Usage,
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param EmbeddingProvider&Provider $provider
     * @param string[] $inputs
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                ...(! str_starts_with($model, 'mistral-embed') ? ['output_dimension' => $dimensions] : []),
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param Provider&TranscriptionProvider $provider
     * @param array<string, mixed> $providerOptions
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
        $params = ['model' => $model];

        if ($diarize) {
            $params['diarize'] = true;
            $params['timestamp_granularities'] = ['segment'];
        } elseif ($language) {
            $params['language'] = $language;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', $this->multipartParams(array_merge($providerOptions, $params))),
        );

        $data = $response->json();

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collect($data['segments'] ?? [])->map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker_id'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new TranscriptionUsage(
                inputTokens: $data['usage']['prompt_tokens'] ?? 0,
                outputTokens: $data['usage']['completion_tokens'] ?? 0,
                audioSeconds: $data['usage']['prompt_audio_seconds'] ?? null,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert request parameters into multipart parts, expanding array values.
     *
     * @param array<string, mixed> $params
     * @return array<int, array{name: string, contents: scalar}>
     */
    protected function multipartParams(array $params): array
    {
        $parts = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? array_values($value) : [$value] as $item) {
                $parts[] = ['name' => $name, 'contents' => $item];
            }
        }

        return $parts;
    }
}
