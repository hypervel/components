<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Image;

use Hypervel\Image\ImagePipeline;

interface Driver
{
    /**
     * Process the given image contents with the specified pipeline.
     *
     * Implementations must treat the pipeline as read-only and must not retain it after this call.
     */
    public function process(string $contents, ImagePipeline $pipeline): string;

    /**
     * Get the dimensions of the given image contents.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $contents): array;

    /**
     * Get the dominant (average) color of the image as a hex string.
     */
    public function dominantColor(string $contents): string;

    /**
     * Register a transformation handler.
     *
     * Boot-only. The handler persists on a cached driver for the worker lifetime and affects every subsequent image processed by that driver.
     *
     * @param class-string<Transformation> $transformation
     */
    public function transformUsing(string $transformation, callable $callback): static;
}
