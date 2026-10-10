<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use Aws\Result;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Support\Collection;
use Throwable;

class BedrockImageGateway implements ImageGateway
{
    use CreatesBedrockClient;
    use HandlesFailoverErrors;

    /**
     * Generate an image using AWS Bedrock.
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
        /** @var ImageProvider&Provider $provider */
        $client = $this->createBedrockClient($provider, $timeout);
        $options = $provider->defaultImageOptions($size, $quality);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn (): Result => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_replace_recursive($providerOptions, $this->prepareImageRequestBody($model, $prompt, $size, $options))),
                ]),
            );
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        $result = json_decode((string) $response->get('body')->getContents(), true);

        return new ImageResponse(
            $this->parseImageResponse($model, $result),
            new ImageUsage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Prepare the request body for the given model family.
     *
     * @param array{quality: string, size: string} $options
     */
    protected function prepareImageRequestBody(string $model, string $prompt, ?string $size, array $options): array
    {
        [$width, $height] = $this->parseSize($size);
        $quality = $options['quality'];

        return match (true) {
            str_starts_with($model, 'stability.') => array_filter([
                'prompt' => $prompt,
                'aspect_ratio' => in_array($size, ['1:1', '2:3', '3:2'], true) ? $size : null,
                'output_format' => 'png',
            ], fn (mixed $value): bool => $value !== null),
            str_starts_with($model, 'amazon.titan-image') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'height' => $height,
                    'width' => $width,
                    'cfgScale' => 7.0,
                ],
            ],
            str_starts_with($model, 'amazon.nova-canvas') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'width' => $width,
                    'height' => $height,
                ],
            ],
            default => ['prompt' => $prompt],
        };
    }

    /**
     * Parse the image response payload into GeneratedImage instances.
     */
    protected function parseImageResponse(string $model, array $result): Collection
    {
        if (str_starts_with($model, 'stability.')
            || str_starts_with($model, 'amazon.titan-image')
            || str_starts_with($model, 'amazon.nova-canvas')) {
            return (new Collection($result['images'] ?? []))
                ->map(fn (?string $image): GeneratedImage => new GeneratedImage($image ?? '', 'image/png'));
        }

        return new Collection;
    }

    /**
     * Parse an aspect-ratio size into explicit [width, height] dimensions.
     *
     * @return array{0: int, 1: int}
     */
    protected function parseSize(?string $size): array
    {
        return match ($size) {
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }
}
