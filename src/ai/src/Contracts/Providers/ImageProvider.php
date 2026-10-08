<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Responses\ImageResponse;

interface ImageProvider extends Provider
{
    /**
     * Generate an image.
     *
     * @param array<Image> $attachments
     * @param null|'high'|'low'|'medium' $quality
     * @param array<string, mixed> $providerOptions
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse;

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway;

    /**
     * Set the provider's image gateway.
     */
    public function useImageGateway(ImageGateway $gateway): self;

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string;

    /**
     * Get the default / normalized image options for the provider.
     *
     * @param null|'high'|'low'|'medium' $quality
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array;
}
