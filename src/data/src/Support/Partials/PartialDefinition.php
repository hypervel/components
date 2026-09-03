<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Partials;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

class PartialDefinition
{
    /**
     * Create a partial definition.
     */
    public function __construct(
        public readonly string $path,
        public readonly bool $permanent = false,
        public readonly ?Closure $condition = null,
    ) {
    }

    /**
     * Determine if the definition applies to the current object.
     */
    public function applies(object $data): bool
    {
        return $this->condition === null || ($this->condition)($data);
    }

    /**
     * Resolve the definition for a nested property.
     */
    public function nested(string $property): ?self
    {
        $segments = explode('.', trim($this->path), 2);
        $current = trim($segments[0]);

        if ($current === '*') {
            return new self('*', $this->permanent);
        }

        if ($current !== $property || ! isset($segments[1])) {
            return null;
        }

        return new self($segments[1], $this->permanent);
    }

    /**
     * Get the serializable representation.
     */
    public function __serialize(): array
    {
        return [
            'path' => $this->path,
            'permanent' => $this->permanent,
            'condition' => $this->condition === null
                ? null
                : new SerializableClosure($this->condition),
        ];
    }

    /**
     * Restore the serialized definition.
     */
    public function __unserialize(array $data): void
    {
        $this->path = $data['path'];
        $this->permanent = $data['permanent'];
        $this->condition = $data['condition']?->getClosure();
    }
}
