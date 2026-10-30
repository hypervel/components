<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Stringable;

readonly class BinaryParameter implements Stringable
{
    /**
     * Create a new binary parameter instance.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Create a binary parameter from a stream and rewind it.
     *
     * @param resource $stream
     */
    public static function fromStream(mixed $stream): static
    {
        $value = stream_get_contents($stream, offset: 0);
        rewind($stream);

        return new static($value);
    }

    /**
     * Return the binary parameter value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
