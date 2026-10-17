<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

use Stringable;

class SchemaPath implements Stringable
{
    /**
     * Create a path through object properties.
     *
     * @param list<string> $segments
     */
    public function __construct(public readonly array $segments = [])
    {
    }

    /**
     * Create the root path.
     */
    public static function root(): self
    {
        return new self;
    }

    /**
     * Create a path to the named child property.
     */
    public function child(string $segment): self
    {
        return new self([...$this->segments, $segment]);
    }

    /**
     * Resolve this path in the supplied arguments.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function valueIn(array $arguments): mixed
    {
        $value = $arguments;

        foreach ($this->segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Convert the path to a JSON pointer.
     */
    public function __toString(): string
    {
        return collect($this->segments)
            ->map(fn (string $segment): string => '/' . str_replace(['~', '/'], ['~0', '~1'], $segment))
            ->implode('');
    }
}
