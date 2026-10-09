<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Events\GeneratingImage;
use Hypervel\Ai\Events\ImageGenerated;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Support\Str;

trait GeneratesImages
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
    ): ImageResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultImageModel();

        $prompt = new ImagePrompt($prompt, $attachments, $size, $quality, $this, $model, $timeout, $providerOptions);

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration($prompt);
        }

        if ($this->events->hasListeners(GeneratingImage::class)) {
            $this->events->dispatch(new GeneratingImage(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->imageGateway()->generateImage(
            $this,
            $model,
            $prompt->prompt,
            $prompt->attachments->all(),
            $prompt->size,
            $prompt->quality,
            $timeout,
            $prompt->providerOptions,
        ), function (ImageResponse $response) use ($invocationId, $prompt, $model): void {
            if ($this->events->hasListeners(ImageGenerated::class)) {
                $this->events->dispatch(new ImageGenerated(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response,
                ));
            }
        });
    }
}
