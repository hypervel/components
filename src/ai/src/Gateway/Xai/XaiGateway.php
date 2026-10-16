<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai;

use Generator;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
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

class XaiGateway implements Gateway, StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesXaiClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;

    /**
     * Create a new xAI gateway.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Generate a single text step.
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
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream a single text step.
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
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        return yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $response->getBody(),
        );
    }

    /**
     * Reject image generation through the text gateway.
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
        throw new LogicException('Use XaiImageGateway for image generation.');
    }

    /**
     * Reject unsupported audio generation.
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
        throw new LogicException('xAI does not support audio generation.');
    }

    /**
     * Reject unsupported transcription generation.
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
        throw new LogicException('xAI does not support transcription generation.');
    }

    /**
     * Reject unsupported embedding generation.
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
        throw new LogicException('xAI does not support embedding generation.');
    }
}
