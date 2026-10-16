<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Support\Collection;
use RuntimeException;

class FakeImageGateway implements ImageGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    /**
     * Create an image gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

    /**
     * Generate an image.
     *
     * @param array<Image> $attachments
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
        $imagePrompt = new ImagePrompt($prompt, $attachments, $size, $quality, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $imagePrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(ImageProvider $provider, string $model, ImagePrompt $prompt): ImageResponse
    {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        ImageProvider $provider,
        string $model,
        ImagePrompt $prompt
    ): ImageResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted image generation without a fake response.');
            }

            $response = base64_encode('fake-image-content');
        }

        if (is_string($response)) {
            return new ImageResponse(
                new Collection([new GeneratedImage($response, 'image/png')]),
                new ImageUsage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any image generation is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayImages(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
