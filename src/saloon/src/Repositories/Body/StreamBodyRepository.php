<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories\Body;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

class StreamBodyRepository implements BodyRepository
{
    use Conditionable;

    /**
     * The stream body.
     *
     * @var null|resource|StreamInterface
     */
    protected mixed $stream = null;

    /**
     * Create a stream body repository.
     *
     * @param null|resource|StreamInterface $value
     */
    public function __construct(mixed $value = null)
    {
        $this->set($value);
    }

    /**
     * Set the repository value.
     *
     * @param null|resource|StreamInterface $value
     * @return $this
     */
    public function set(mixed $value): static
    {
        if (isset($value) && ! $value instanceof StreamInterface && ! is_resource($value)) {
            throw new InvalidArgumentException('The body value must be a resource, null, or an instance of ' . StreamInterface::class . '.');
        }

        $this->stream = $value;

        return $this;
    }

    /**
     * Get the stream from the repository.
     *
     * @return null|resource|StreamInterface
     */
    public function all(): mixed
    {
        return $this->stream;
    }

    /**
     * Get the stream from the repository.
     *
     * Alias of "all" method.
     */
    public function get(): mixed
    {
        return $this->all();
    }

    /**
     * Determine if the repository is empty.
     */
    public function isEmpty(): bool
    {
        return is_null($this->stream);
    }

    /**
     * Determine if the repository is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Convert the body repository into a stream.
     */
    public function toStream(): StreamInterface
    {
        return $this->stream instanceof StreamInterface
            ? $this->stream
            : Utils::streamFor($this->stream);
    }
}
