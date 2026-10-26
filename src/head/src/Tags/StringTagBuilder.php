<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;

/**
 * A tag builder holding a single string value.
 *
 * @phpstan-consistent-constructor
 */
abstract class StringTagBuilder extends TagBuilder
{
    /**
     * Create a tag with an optional string value.
     */
    public function __construct(protected ?string $value = null)
    {
    }

    /**
     * Create a tag from a string value.
     */
    public static function make(string $value): static
    {
        return new static($value);
    }

    /**
     * Build a string tag from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        return $key === static::key() && is_string($value)
            ? static::make($value)
            : null;
    }

    /**
     * Overlay this value onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static($this->value ?? $base->value);
    }

    /**
     * Determine whether no string value is defined.
     */
    public function isEmpty(): bool
    {
        return is_null($this->value);
    }

    /**
     * Get the tag value.
     */
    public function render(): ?string
    {
        return $this->value;
    }

    /**
     * Get the value for the head array.
     */
    public function toHeadArray(ResolvedHead $head): ?string
    {
        return $this->render();
    }
}
