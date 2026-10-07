<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use InvalidArgumentException;

trait HasDelay
{
    /**
     * The request delay in milliseconds.
     */
    protected ?int $delay = null;

    /**
     * Delay the request by the given milliseconds.
     *
     * @return $this
     */
    public function delay(int $milliseconds): static
    {
        if ($milliseconds < 0 || $milliseconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new InvalidArgumentException('The request delay must be a representable non-negative number of milliseconds.');
        }

        $this->delay = $milliseconds;

        return $this;
    }

    /**
     * Get the request delay in milliseconds.
     */
    public function delayMilliseconds(): ?int
    {
        return $this->delay ?? $this->defaultDelay();
    }

    /**
     * Resolve the default request delay.
     */
    protected function defaultDelay(): ?int
    {
        return null;
    }
}
