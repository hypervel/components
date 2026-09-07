<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories\Body;

use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Traits\Body\CreatesStreamFromString;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class StringBodyRepository implements BodyRepository, Stringable
{
    use CreatesStreamFromString;
    use Conditionable;

    /**
     * The repository data.
     */
    protected ?string $data = null;

    /**
     * Create a string body repository.
     */
    public function __construct(?string $value = null)
    {
        $this->set($value);
    }

    /**
     * Set the repository value.
     *
     * @param null|string $value
     * @return $this
     */
    public function set(mixed $value): static
    {
        $this->data = $value;

        return $this;
    }

    /**
     * Get the repository value.
     */
    public function all(): ?string
    {
        return $this->data;
    }

    /**
     * Determine if the repository is empty.
     */
    public function isEmpty(): bool
    {
        return $this->data === null || $this->data === '';
    }

    /**
     * Determine if the repository is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Convert the repository into a string.
     */
    public function __toString(): string
    {
        return $this->all() ?? '';
    }
}
