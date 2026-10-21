<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Gateway\FakeImageGateway;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Prompts\QueuedImagePrompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeImages
{
    /**
     * The fake image gateway instance.
     */
    protected ?FakeImageGateway $fakeImageGateway = null;

    /**
     * All of the recorded image generations.
     */
    protected array $recordedImageGenerations = [];

    /**
     * All of the recorded image generations that were queued.
     */
    protected array $recordedQueuedImageGenerations = [];

    /**
     * Fake image generation.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeImages(Closure|array $responses = []): FakeImageGateway
    {
        return $this->fakeImageGateway = new FakeImageGateway($responses);
    }

    /**
     * Record an image generation.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordImageGeneration(ImagePrompt|QueuedImagePrompt $prompt): self
    {
        if ($prompt instanceof QueuedImagePrompt) {
            $this->recordedQueuedImageGenerations[] = $prompt;
        } else {
            $this->recordedImageGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that an image was generated matching a given truth test.
     */
    public function assertImageGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedImageGenerations))->contains(fn (ImagePrompt $prompt): mixed => $callback($prompt)),
            'An expected image generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     */
    public function assertImageNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedImageGenerations))->doesntContain(fn (ImagePrompt $prompt): mixed => $callback($prompt)),
            'An unexpected image generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no images were generated.
     */
    public function assertNoImagesGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedImageGenerations,
            'Unexpected image generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued image generation was recorded matching a given truth test.
     */
    public function assertImageQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedImageGenerations))->contains(fn (QueuedImagePrompt $prompt): mixed => $callback($prompt)),
            'An expected queued image generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued image generation was not recorded matching a given truth test.
     */
    public function assertImageNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedImageGenerations))->doesntContain(fn (QueuedImagePrompt $prompt): mixed => $callback($prompt)),
            'An unexpected queued image generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued image generations were recorded.
     */
    public function assertNoImagesQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedImageGenerations,
            'Unexpected queued image generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if image generation is faked.
     *
     * @phpstan-assert-if-true FakeImageGateway $this->fakeImageGateway()
     */
    public function imagesAreFaked(): bool
    {
        return $this->fakeImageGateway !== null;
    }

    /**
     * Get the fake image gateway.
     */
    public function fakeImageGateway(): ?FakeImageGateway
    {
        return $this->fakeImageGateway;
    }
}
