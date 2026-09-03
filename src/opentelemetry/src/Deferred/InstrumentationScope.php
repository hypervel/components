<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred;

/**
 * Retain a replayable OpenTelemetry instrumentation-scope descriptor.
 *
 * @internal
 */
class InstrumentationScope
{
    /** @var array<non-empty-string, null|array|bool|float|int|string> */
    public readonly array $attributes;

    /**
     * Create an instrumentation-scope descriptor.
     *
     * @param iterable<non-empty-string, null|array|bool|float|int|string> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
        public readonly ?string $schemaUrl = null,
        iterable $attributes = [],
    ) {
        $this->attributes = is_array($attributes)
            ? $attributes
            : iterator_to_array($attributes);
    }
}
