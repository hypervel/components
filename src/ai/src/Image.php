<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Gateway\FakeImageGateway;
use Hypervel\Ai\PendingResponses\PendingImageGeneration;
use InvalidArgumentException;

class Image
{
    /**
     * Generate an image.
     *
     * @throws InvalidArgumentException if the given prompt is empty or whitespace-only
     */
    public static function of(string $prompt): PendingImageGeneration
    {
        return new PendingImageGeneration($prompt);
    }

    /**
     * Fake image generation.
     *
     * Tests only. The fake gateway is shared across requests in the worker.
     */
    public static function fake(Closure|array $responses = []): FakeImageGateway
    {
        return Ai::fakeImages($responses);
    }

    /**
     * Assert that an image was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertImageGenerated($callback);
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertImageNotGenerated($callback);
    }

    /**
     * Assert that no images were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoImagesGenerated();
    }

    /**
     * Assert that a queued image generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertImageQueued($callback);
    }

    /**
     * Assert that a queued image generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertImageNotQueued($callback);
    }

    /**
     * Assert that no queued image generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoImagesQueued();
    }

    /**
     * Determine if image generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::imagesAreFaked();
    }
}
