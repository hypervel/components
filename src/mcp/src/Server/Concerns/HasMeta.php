<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

use InvalidArgumentException;

trait HasMeta
{
    /**
     * @var null|array<string, mixed>
     */
    protected ?array $meta = null;

    /**
     * Set response metadata using an array or key-value pair.
     *
     * @param array<string, mixed>|string $meta
     */
    public function setMeta(array|string $meta, mixed $value = null): void
    {
        $this->meta ??= [];

        if (! is_array($meta)) {
            if (is_null($value)) {
                throw new InvalidArgumentException('Value is required when using key-value signature.');
            }

            $this->meta[$meta] = $value;

            return;
        }

        $this->meta = array_merge($this->meta, $meta);
    }

    /**
     * Merge metadata into the given response array.
     *
     * @template T of array<string, mixed>
     *
     * @param T $baseArray
     * @return array{_meta?: array<string, mixed>}&T
     */
    public function mergeMeta(array $baseArray): array
    {
        return ($meta = $this->meta)
            ? [...$baseArray, '_meta' => $meta]
            : $baseArray;
    }
}
