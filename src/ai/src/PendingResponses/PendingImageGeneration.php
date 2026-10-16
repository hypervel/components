<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Ai\Jobs\GenerateImage;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Prompts\QueuedImagePrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Ai\Responses\QueuedImageResponse;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;

class PendingImageGeneration
{
    use Conditionable;
    use ResolvesProviderOptions;

    public array $attachments = [];

    public ?string $size = null;

    public ?string $quality = null;

    public ?int $timeout = null;

    /**
     * Create a pending image generation.
     */
    public function __construct(public string $prompt)
    {
        if (blank($prompt)) {
            throw new InvalidArgumentException('A prompt is required to generate an image.');
        }
    }

    /**
     * Provide the reference images that should be sent with the request.
     *
     * @param array<Image> $attachments
     */
    public function attachments(array $attachments): self
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * Specify the size / aspect ratio of the generated image.
     */
    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Indicate that the generated image should have a square aspect ratio.
     */
    public function square(): self
    {
        $this->size = '1:1';

        return $this;
    }

    /**
     * Indicate that the generated image should have a portrait aspect ratio.
     */
    public function portrait(): self
    {
        $this->size = '2:3';

        return $this;
    }

    /**
     * Indicate that the generated image should have a landscape aspect ratio.
     */
    public function landscape(): self
    {
        $this->size = '3:2';

        return $this;
    }

    /**
     * Specify the quality of the generated image.
     *
     * @param 'high'|'low'|'medium' $quality
     */
    public function quality(string $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Specify the timeout for the image generation request.
     */
    public function timeout(?int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Generate the image.
     *
     * @throws FailoverableException if every configured provider fails to generate the image
     */
    public function generate(Provider|Lab|array|string|null $provider = null, ?string $model = null): ImageResponse
    {
        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_images'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableImageProvider($provider);

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $model ??= $provider->defaultImageModel();

            try {
                return $provider->withHeaders($headers)->image(
                    $this->prompt,
                    $this->attachments,
                    $this->size,
                    $this->quality,
                    $model,
                    $this->timeout,
                    $providerOptions
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                if (Event::hasListeners(ProviderFailedOver::class)) {
                    Event::dispatch(new ProviderFailedOver($provider, $model, $e));
                }

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of an image.
     *
     * @throws LogicException if any attachment is not a local image or an image stored on a filesystem disk
     */
    public function queue(Provider|Lab|array|string|null $provider = null, ?string $model = null): QueuedImageResponse
    {
        $this->ensureAttachmentsAreQueueable();

        if (Ai::imagesAreFaked()) {
            Ai::recordImageGeneration(
                new QueuedImagePrompt(
                    $this->prompt,
                    $this->attachments,
                    $this->size,
                    $this->quality,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                )
            );
        }

        return new QueuedImageResponse(
            GenerateImage::dispatch($this, $provider, $model),
        );
    }

    /**
     * Ensure all of the attachments are queueable.
     */
    protected function ensureAttachmentsAreQueueable(): void
    {
        foreach ($this->attachments as $attachment) {
            if (! $attachment instanceof StoredImage
                && ! $attachment instanceof LocalImage) {
                throw new LogicException('Only local images or images stored on a filesystem disk may be attachments for queued image generations.');
            }
        }
    }
}
