<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Anthropic;

use Generator;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\Anthropic\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\Anthropic\Concerns\CreatesAnthropicClient;
use Hypervel\Ai\Gateway\Anthropic\Concerns\HandlesTextStreaming;
use Hypervel\Ai\Gateway\Anthropic\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\Anthropic\Concerns\MapsMessages;
use Hypervel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Hypervel\Ai\Gateway\Anthropic\Concerns\ParsesTextResponses;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Contracts\Events\Dispatcher;
use LogicException;

class AnthropicGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesAnthropicClient;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;

    /**
     * Create an Anthropic gateway instance.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Generate text for a single step in a conversation.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        /** @var Provider&TextProvider $provider */
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('messages', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single step in a conversation.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        /** @var Provider&TextProvider $provider */
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('messages', $body),
        );

        return yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $response->getBody(),
        );
    }

    /**
     * Generate an image.
     *
     * @throws LogicException
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
        throw new LogicException('Anthropic does not support image generation.');
    }

    /**
     * Generate audio from the given text.
     *
     * @throws LogicException
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
        throw new LogicException('Anthropic does not support audio generation.');
    }

    /**
     * Generate text from the given audio.
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
        throw new LogicException('Anthropic does not support transcription generation.');
    }

    /**
     * Generate embeddings for the given inputs.
     *
     * @throws LogicException
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        throw new LogicException('Anthropic does not support embedding generation.');
    }

    /**
     * Get the status codes that indicate a provider is transiently unavailable and the request should fail over.
     */
    protected function overloadedStatusCodes(): array
    {
        // 529 is Anthropic's own "overloaded" status, plus the shared transient gateway and Cloudflare codes.
        return [529, 502, 503, 504, 520, 522, 524];
    }

    /**
     * Get the patterns used to detect insufficient credits or quota errors.
     */
    protected function insufficientCreditPatterns(): array
    {
        return [
            'credit balance',
            'insufficient',
            'quota exceeded',
            'exceeded your current quota',
            'billing',
            'usage limit',
        ];
    }
}
