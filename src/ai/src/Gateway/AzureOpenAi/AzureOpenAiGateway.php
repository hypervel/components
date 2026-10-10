<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\AzureOpenAi;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Gateway\AzureOpenAi\Concerns\CreatesAzureOpenAiClient;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\OpenAi\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\OpenAi\Concerns\HandlesTextGeneration;
use Hypervel\Ai\Gateway\OpenAi\Concerns\HandlesTextSteps;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsMessages;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Hypervel\Ai\Gateway\OpenAi\Concerns\ParsesTextResponses;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use LogicException;

class AzureOpenAiGateway implements EmbeddingGateway, ImageGateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesAzureOpenAiClient;
    use HandlesFailoverErrors;
    use HandlesTextGeneration;
    use HandlesTextSteps;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    /**
     * Create an Azure OpenAI gateway instance.
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

    /**
     * Generate an image.
     *
     * @param array<Image> $attachments
     * @param null|'1:1'|'2:3'|'3:2' $size
     * @param null|'high'|'low'|'medium' $quality
     * @param array<string, mixed> $providerOptions
     *
     * @throws LogicException if attachments are passed; Azure OpenAI does not support image edits
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
        if (filled($attachments)) {
            throw new LogicException('Azure OpenAI does not support image editing.');
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)->post('images/generations', [
                ...$providerOptions,
                'model' => $model,
                'prompt' => $prompt,
                'moderation' => 'low',
                ...$provider->defaultImageOptions($size, $quality),
            ]),
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
     * Map a regular tool to an Azure OpenAI function definition.
     */
    protected function mapTool(Tool $tool, bool $defer = false): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        $definition = array_filter([
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'parameters' => filled($schemaArray) ? [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object) [],
                'required' => $schemaArray['required'] ?? [],
            ] : null,
        ]);

        if ($defer) {
            $definition['defer_loading'] = true;
        }

        return $definition;
    }
}
