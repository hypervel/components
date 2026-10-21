<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final class MultipartValue
{
    /**
     * The stream that wraps a resource value.
     */
    private ?StreamInterface $resourceStream = null;

    /**
     * Create a multipart value.
     *
     * Guzzle expands an array value into one field per element, named with brackets such as `roles[0]`, so an array
     * cannot have a filename or headers.
     *
     * @param null|array<array-key, mixed>|bool|float|int|resource|StreamInterface|string $value
     * @param array<string, list<string>|string> $headers
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ?string $filename = null,
        public readonly array $headers = [],
    ) {
        if (is_array($value)) {
            if ($filename !== null || $headers !== []) {
                throw new InvalidArgumentException('An array value cannot have a filename or headers.');
            }
        } elseif ($value !== null
            && ! is_bool($value)
            && ! $value instanceof StreamInterface
            && ! is_resource($value)
            && ! is_string($value)
            && ! is_int($value)
            && (! is_float($value) || ! is_finite($value))) {
            throw new InvalidArgumentException(sprintf('The value property must be either a %s, resource, string, finite number, boolean, null, or array.', StreamInterface::class));
        }
    }

    /**
     * Get the contents to add to a multipart stream.
     *
     * @return null|array<array-key, mixed>|bool|float|int|StreamInterface|string
     */
    public function contents(): array|bool|float|int|StreamInterface|string|null
    {
        if (! is_resource($this->value)) {
            return $this->value;
        }

        // A Guzzle stream closes its resource when destroyed, so every multipart stream built from this value shares
        // one wrapper. Otherwise releasing one, such as a PSR request snapshot taken before sending, closes the file.
        return $this->resourceStream ??= Utils::streamFor($this->value);
    }
}
