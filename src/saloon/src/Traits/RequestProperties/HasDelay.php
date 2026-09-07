<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Hypervel\Saloon\Repositories\IntegerRepository;
use InvalidArgumentException;

trait HasDelay
{
    /**
     * The request delay.
     */
    protected ?IntegerRepository $delayRepository = null;

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

        $this->delayRepository()->set($milliseconds);

        return $this;
    }

    /**
     * Get the request delay in milliseconds.
     */
    public function delayMilliseconds(): ?int
    {
        return $this->delayRepository()->get();
    }

    /**
     * Resolve the default request delay.
     */
    protected function defaultDelay(): ?int
    {
        return null;
    }

    /**
     * Get the request delay repository.
     */
    protected function delayRepository(): IntegerRepository
    {
        return $this->delayRepository ??= new IntegerRepository($this->defaultDelay());
    }
}
