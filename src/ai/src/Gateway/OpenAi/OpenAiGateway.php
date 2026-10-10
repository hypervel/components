<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Hypervel\Ai\Gateway\OpenAi\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Hypervel\Ai\Gateway\OpenAi\Concerns\HandlesTextGeneration;
use Hypervel\Ai\Gateway\OpenAi\Concerns\HandlesTextSteps;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Hypervel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Client\Response;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use LogicException;

class OpenAiGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesOpenAiClient;
    use HandlesTextGeneration;
    use HandlesTextSteps;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;
    use ResolvesAudioFilenames;

    /**
     * Create an OpenAI gateway instance.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Generate an image.
     *
     * @param array<Image> $attachments
     * @param null|'1:1'|'2:3'|'3:2' $size
     * @param null|'high'|'low'|'medium' $quality
     * @param array<string, mixed> $providerOptions
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
        $hasAttachments = filled($attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $hasAttachments
                ? $this->sendImageEditRequest($provider, $model, $prompt, $attachments, $size, $quality, $timeout, $providerOptions)
                : $this->sendImageGenerationRequest($provider, $model, $prompt, $size, $quality, $timeout, $providerOptions),
        );

        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Send an image generation request.
     *
     * @param array<string, mixed> $providerOptions
     */
    protected function sendImageGenerationRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        ?string $size,
        ?string $quality,
        ?int $timeout,
        array $providerOptions = [],
    ): Response {
        /** @var ImageProvider&Provider $provider */
        return $this->client($provider, $timeout ?? 120)->post('images/generations', [
            ...$providerOptions,
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...(str_starts_with($model, 'gpt-image')
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ]);
    }

    /**
     * Send an image edit request with attachments.
     *
     * @param array<string, mixed> $providerOptions
     */
    protected function sendImageEditRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments,
        ?string $size,
        ?string $quality,
        ?int $timeout,
        array $providerOptions = [],
    ): Response {
        /** @var ImageProvider&Provider $provider */
        $request = $this->client($provider, $timeout ?? 120);

        $isGptImage = str_starts_with($model, 'gpt-image');
        $field = $isGptImage ? 'image[]' : 'image';

        foreach ($attachments as $attachment) {
            $content = match (true) {
                $attachment instanceof Image && $attachment instanceof StorableFile => $attachment->content(),
                $attachment instanceof UploadedFile => $attachment->get(),
                default => throw new InvalidArgumentException('Unsupported image attachment type [' . get_debug_type($attachment) . ']'),
            };

            $request = $request->attach($field, $content, 'image.png');
        }

        return $request->post('images/edits', array_merge($providerOptions, array_filter([
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...($isGptImage
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ])));
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
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('audio/speech', array_merge(['speed' => 1.0], $providerOptions, array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
                'instructions' => $instructions,
            ]))),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Usage,
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param array<string, mixed> $providerOptions
     *
     * @throws LogicException if diarization is requested together with the `prompt` provider option
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
        if ($diarize && filled($providerOptions['prompt'] ?? null)) {
            throw new LogicException('OpenAI does not support the `prompt` option for diarized transcriptions.');
        }

        if ($provider->driver() === 'openai' && ! $diarize) {
            $model = str_replace('-diarize', '', $model);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]))),
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
                inputTokens: Arr::get($data, 'usage.input_tokens', 0),
                outputTokens: Arr::get($data, 'usage.output_tokens', 0),
                audioSeconds: Arr::get($data, 'usage.seconds') ?? Arr::get($data, 'duration'),
            ),
            new Meta($provider->name(), $model),
        );
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ])),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            collect($data['data'] ?? [])->pluck('embedding')->all(),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }
}
