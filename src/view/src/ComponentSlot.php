<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hypervel\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Stringable;

class ComponentSlot implements Htmlable, Stringable
{
    /**
     * The slot attribute bag.
     */
    public ComponentAttributeBag $attributes;

    /**
     * Create a new slot instance.
     */
    public function __construct(
        protected string $contents = '',
        array $attributes = []
    ) {
        $this->withAttributes($attributes);
    }

    /**
     * Set the extra attributes that the slot should make available.
     */
    public function withAttributes(array $attributes): static
    {
        $this->attributes = new ComponentAttributeBag($attributes);

        return $this;
    }

    /**
     * Get the slot's HTML string.
     */
    public function toHtml(): string
    {
        return $this->contents;
    }

    /**
     * Determine if the slot is empty.
     */
    public function isEmpty(): bool
    {
        return $this->contents === '';
    }

    /**
     * Determine if the slot is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Determine if the slot has non-comment content.
     */
    public function hasActualContent(callable|string|null $callable = null): bool
    {
        if (is_string($callable) && ! function_exists($callable)) {
            throw new InvalidArgumentException('Callable does not exist.');
        }

        return filter_var(
            $this->contents,
            FILTER_CALLBACK,
            ['options' => $callable ?? fn ($input) => trim(preg_replace('/<!--([\s\S]*?)-->/', '', $input))]
        ) !== '';
    }

    /**
     * Get the slot's HTML string.
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }
}
