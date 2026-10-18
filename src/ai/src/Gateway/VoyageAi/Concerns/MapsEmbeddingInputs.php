<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\VoyageAi\Concerns;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Base64Video;
use Hypervel\Ai\Files\Image as ImageFile;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Ai\Files\Video as VideoFile;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Support\Collection;
use InvalidArgumentException;

trait MapsEmbeddingInputs
{
    /**
     * Determine if the model or inputs require Voyage AI's multimodal endpoint.
     */
    protected function usesMultimodalEmbeddingEndpoint(string $model, array $inputs): bool
    {
        return in_array($model, ['voyage-multimodal-3.5', 'voyage-multimodal-3'], true)
            || array_any($inputs, fn (mixed $input): bool => ! is_string($input));
    }

    /**
     * Generate embeddings for text and image inputs using Voyage AI's multimodal endpoint.
     */
    protected function generateMultimodalEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        /** @var EmbeddingProvider&Provider $provider */
        if ($model === 'voyage-multimodal-3' && $dimensions !== 1024) {
            throw new InvalidArgumentException(
                'Model [voyage-multimodal-3] only supports 1024 dimension embeddings. Use [voyage-multimodal-3.5] for other dimensions.'
            );
        }

        $this->validateMultimodalEmbeddingInputSources($inputs);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/multimodalembeddings', array_merge($providerOptions, [
                'model' => $model,
                'inputs' => array_map(fn (mixed $input): array => [
                    'content' => [$this->mapMultimodalEmbeddingInput($input)],
                ], $inputs),
            ], $model === 'voyage-multimodal-3' ? [] : ['output_dimension' => $dimensions])),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->pluck('embedding')->all(),
            new Usage($data['usage']['total_tokens'] ?? $data['total_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Validate Voyage AI's per-request media source constraint.
     */
    protected function validateMultimodalEmbeddingInputSources(array $inputs): void
    {
        $source = null;

        foreach ($inputs as $input) {
            $inputSource = $this->multimodalEmbeddingInputSource($input);

            if (is_null($inputSource)) {
                continue;
            }

            $source ??= $inputSource;

            if ($source !== $inputSource) {
                throw new InvalidArgumentException(
                    'Voyage AI multimodal embeddings inputs must use either URL media or base64 media exclusively.'
                );
            }
        }
    }

    /**
     * Get the media source type used by a multimodal embeddings input.
     */
    protected function multimodalEmbeddingInputSource(mixed $input): ?string
    {
        return match (true) {
            $input instanceof RemoteImage,
            $input instanceof RemoteVideo => 'url',
            $input instanceof ImageFile && $input instanceof StorableFile && ! $input instanceof ProviderImage,
            $input instanceof VideoFile && $input instanceof StorableFile => 'base64',
            default => null,
        };
    }

    /**
     * Map a Hypervel embeddings input to Voyage AI's multimodal input segment.
     */
    protected function mapMultimodalEmbeddingInput(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'text' => $input,
            ];
        }

        if ($input instanceof RemoteImage) {
            return [
                'type' => 'image_url',
                'image_url' => $input->url,
            ];
        }

        if ($input instanceof RemoteVideo) {
            return [
                'type' => 'video_url',
                'video_url' => $input->url,
            ];
        }

        if ($input instanceof Base64Image) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64," . $input->base64,
            ];
        }

        if ($input instanceof Base64Video) {
            $mime = $input->mimeType() ?? 'video/mp4';

            return [
                'type' => 'video_base64',
                'video_base64' => "data:{$mime};base64," . $input->base64,
            ];
        }

        if ($input instanceof ImageFile && $input instanceof StorableFile && ! $input instanceof ProviderImage) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64," . base64_encode($input->content()),
            ];
        }

        if ($input instanceof VideoFile && $input instanceof StorableFile) {
            $mime = $input->mimeType() ?? 'video/mp4';

            return [
                'type' => 'video_base64',
                'video_base64' => "data:{$mime};base64," . base64_encode($input->content()),
            ];
        }

        throw new InvalidArgumentException('Unsupported Voyage AI multimodal embeddings input type [' . get_debug_type($input) . ']');
    }
}
