<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Repositories\Body;

use GuzzleHttp\Psr7\MultipartStream;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

class MultipartBodyRepository implements BodyRepository, MergeableBody
{
    use Conditionable;

    /**
     * The multipart values.
     *
     * @var list<MultipartValue>
     */
    protected array $data = [];

    /**
     * The multipart boundary.
     */
    protected string $boundary;

    /**
     * Create a multipart body repository.
     *
     * @param list<MultipartValue> $value
     */
    public function __construct(array $value = [], ?string $boundary = null)
    {
        $this->boundary = $boundary ?? Str::random(40);

        $this->set($value);
    }

    /**
     * Set the repository value.
     *
     * @param list<MultipartValue> $value
     * @return $this
     */
    public function set(mixed $value): static
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The multipart body value must be an array.');
        }

        $this->data = $this->parseMultipartArray($value);

        return $this;
    }

    /**
     * Merge arrays into the repository.
     *
     * @param list<MultipartValue> ...$arrays
     * @return $this
     */
    public function merge(array ...$arrays): static
    {
        foreach ($arrays as $array) {
            $this->data = array_merge($this->data, $this->parseMultipartArray($array));
        }

        return $this;
    }

    /**
     * Add an element to the repository.
     *
     * @param float|int|resource|StreamInterface|string $contents
     * @param array<string, list<string>|string> $headers
     * @return $this
     */
    public function add(string $name, mixed $contents, ?string $filename = null, array $headers = []): static
    {
        $this->attach(new MultipartValue($name, $contents, $filename, $headers));

        return $this;
    }

    /**
     * Attach a multipart value.
     *
     * @return $this
     */
    public function attach(MultipartValue $file): static
    {
        $this->data[] = $file;

        return $this;
    }

    /**
     * Get the raw data in the repository.
     *
     * @return list<MultipartValue>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Get values with the given name.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $values = array_values(array_filter($this->all(), static function (MultipartValue $value) use ($key) {
            return $value->name === $key;
        }));

        if (count($values) === 0) {
            return $default;
        }

        if (count($values) === 1) {
            return $values[0];
        }

        return $values;
    }

    /**
     * Remove an item from the repository.
     *
     * @return $this
     */
    public function remove(string $key): static
    {
        $values = array_filter($this->all(), static function (MultipartValue $value) use ($key) {
            return $value->name !== $key;
        });

        $this->set($values);

        return $this;
    }

    /**
     * Determine if the repository is empty.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Determine if the repository is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->data !== [];
    }

    /**
     * Parse a multipart array.
     *
     * @param array<array-key, mixed> $value
     * @return list<MultipartValue>
     */
    protected function parseMultipartArray(array $value): array
    {
        $multipartValues = array_filter($value, static fn (mixed $item): bool => $item instanceof MultipartValue);

        if (count($value) !== count($multipartValues)) {
            throw new InvalidArgumentException(sprintf('The value array must only contain %s objects.', MultipartValue::class));
        }

        return array_values($multipartValues);
    }

    /**
     * Get the multipart boundary.
     */
    public function boundary(): string
    {
        return $this->boundary;
    }

    /**
     * Get the boundary-aware multipart content type.
     */
    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /**
     * Convert the body repository into a stream.
     */
    public function toStream(): StreamInterface
    {
        $parts = array_map(static function (MultipartValue $value): array {
            $part = [
                'name' => $value->name,
                'contents' => is_int($value->value) || is_float($value->value)
                    ? (string) $value->value
                    : $value->value,
            ];

            if ($value->filename !== null) {
                $part['filename'] = $value->filename;
            }

            if ($value->headers !== []) {
                $part['headers'] = $value->headers;
            }

            return $part;
        }, $this->data);

        return new MultipartStream($parts, $this->boundary);
    }
}
