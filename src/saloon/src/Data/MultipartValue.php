<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final readonly class MultipartValue
{
    /**
     * Create a multipart value.
     *
     * @param float|int|resource|StreamInterface|string $value
     * @param array<string, list<string>|string> $headers
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public ?string $filename = null,
        public array $headers = [],
    ) {
        if (! $value instanceof StreamInterface
            && ! is_resource($value)
            && ! is_string($value)
            && ! is_int($value)
            && (! is_float($value) || ! is_finite($value))) {
            throw new InvalidArgumentException(sprintf('The value property must be either a %s, resource, string, or finite number.', StreamInterface::class));
        }
    }
}
