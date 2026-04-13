<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

trait HasSpinner
{
    /**
     * The frames of the spinner (single dot moving around the perimeter).
     *
     * @var array<string>
     */
    protected array $frames = ['⠂', '⠒', '⠐', '⠰', '⠠', '⠤', '⠄', '⠆'];

    /**
     * The frame to render when the spinner is static.
     */
    protected string $staticFrame = '⠶';

    /**
     * The interval between frames.
     */
    protected int $interval = 75;

    /**
     * Get the spinner frame for the given count.
     */
    public function spinnerFrame(int $count): string
    {
        return $this->frames[$count % count($this->frames)];
    }
}
