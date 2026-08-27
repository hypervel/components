<?php

declare(strict_types=1);

namespace Hypervel\Pipeline;

/**
 * An immutable description of a class-string pipeline pipe.
 */
final readonly class PipeDescriptor
{
    /**
     * @param array<int, string> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public ?string $method = null,
    ) {
    }

    /**
     * Create a descriptor from a pipeline string.
     */
    public static function fromString(string $pipe, ?string $method = null): self
    {
        if (! str_contains($pipe, ':')) {
            return new self($pipe, method: $method);
        }

        [$name, $parameters] = explode(':', $pipe, 2);

        return new self($name, explode(',', $parameters), $method);
    }
}
