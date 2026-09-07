<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories;

use Hypervel\Support\Traits\Conditionable;

class IntegerRepository
{
    use Conditionable;

    /**
     * The repository value.
     */
    protected ?int $data = null;

    /**
     * Create an integer repository.
     */
    public function __construct(?int $value = null)
    {
        $this->set($value);
    }

    /**
     * Set the repository value.
     *
     * @return $this
     */
    public function set(?int $value): static
    {
        $this->data = $value;

        return $this;
    }

    /**
     * Get the repository value.
     */
    public function get(): ?int
    {
        return $this->data;
    }

    /**
     * Determine if the repository is empty.
     */
    public function isEmpty(): bool
    {
        return $this->data === null;
    }

    /**
     * Determine if the repository is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }
}
