<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<string, list<string>> */
final class Metadata implements Countable, IteratorAggregate
{
    /** @internal */
    public const OWNED_KEYS = [
        'host',
        'content-type',
        'content-length',
        'content-encoding',
        'cache-control',
        'accept-encoding',
        'transfer-encoding',
        'te',
        'trailer',
        'user-agent',
        'server',
        'date',
        'cookie',
        'set-cookie',
        'connection',
        'keep-alive',
        'proxy-connection',
        'upgrade',
    ];

    /**
     * @param array<string, list<string>> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Create metadata from raw application values.
     *
     * @param array<string, list<string>|string> $values
     */
    public static function make(array $values = []): self
    {
        $normalized = [];

        foreach ($values as $key => $rawValues) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('gRPC metadata keys must be strings.');
            }

            $key = self::normalizeKey($key);
            $rawValues = is_array($rawValues) ? $rawValues : [$rawValues];

            if ($rawValues === [] || ! array_is_list($rawValues)) {
                throw new InvalidArgumentException('gRPC metadata values must be a non-empty list.');
            }

            foreach ($rawValues as $value) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('gRPC metadata values must be strings.');
                }

                self::validateValue($key, $value);
                $normalized[$key][] = $value;
            }
        }

        return new self($normalized);
    }

    /**
     * Append values to a metadata key.
     */
    public function with(string $key, string ...$values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('At least one gRPC metadata value is required.');
        }

        $key = self::normalizeKey($key);
        $metadata = $this->values;

        foreach ($values as $value) {
            self::validateValue($key, $value);
            $metadata[$key][] = $value;
        }

        return new self($metadata);
    }

    /**
     * Remove a metadata key.
     */
    public function without(string $key): self
    {
        $key = self::normalizeKey($key);

        if (! isset($this->values[$key])) {
            return $this;
        }

        $metadata = $this->values;
        unset($metadata[$key]);

        return new self($metadata);
    }

    /**
     * Append another metadata collection.
     *
     * @param array<string, list<string>|string>|self $metadata
     */
    public function merge(self|array $metadata): self
    {
        $metadata = is_array($metadata) ? self::make($metadata) : $metadata;
        $merged = $this->values;

        foreach ($metadata->values as $key => $values) {
            foreach ($values as $value) {
                $merged[$key][] = $value;
            }
        }

        return new self($merged);
    }

    /**
     * Return the first value for a metadata key.
     */
    public function first(string $key, ?string $default = null): ?string
    {
        $key = self::normalizeKey($key);

        return $this->values[$key][0] ?? $default;
    }

    /**
     * Return every value for a metadata key.
     *
     * @return list<string>
     */
    public function values(string $key): array
    {
        $key = self::normalizeKey($key);

        return $this->values[$key] ?? [];
    }

    /**
     * Determine whether a metadata key exists.
     */
    public function has(string $key): bool
    {
        return isset($this->values[self::normalizeKey($key)]);
    }

    /**
     * Determine whether the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Return every metadata value.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Count the metadata keys.
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * Return an iterator over the metadata keys and values.
     *
     * @return Traversable<string, list<string>>
     */
    public function getIterator(): Traversable
    {
        yield from $this->values;
    }

    /**
     * Normalize and validate a metadata key.
     */
    private static function normalizeKey(string $key): string
    {
        $key = strtolower($key);

        if (preg_match('/^[0-9a-z_.-]+$/D', $key) !== 1) {
            throw new InvalidArgumentException('The gRPC metadata key contains invalid characters.');
        }

        if (str_starts_with($key, 'grpc-') || in_array($key, self::OWNED_KEYS, true)) {
            throw new InvalidArgumentException('The gRPC metadata key is reserved by the protocol or transport.');
        }

        return $key;
    }

    /**
     * Validate an application metadata value.
     */
    private static function validateValue(string $key, string $value): void
    {
        if (str_ends_with($key, '-bin')) {
            return;
        }

        if (preg_match('/^[\x20-\x7e]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('ASCII gRPC metadata values must contain only printable ASCII bytes.');
        }

        if ($value !== '' && ($value[0] === ' ' || $value[-1] === ' ')) {
            throw new InvalidArgumentException('ASCII gRPC metadata values cannot have surrounding whitespace.');
        }
    }
}
