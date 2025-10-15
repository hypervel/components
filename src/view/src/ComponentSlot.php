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
     *
     * @var \Hypervel\View\ComponentAttributeBag
     */
    public ComponentAttributeBag $attributes;

    /**
     * The slot contents.
     *
     * @var string
     */
    protected string $contents;

    /**
     * Create a new slot instance.
     *
     * @param  string  $contents
     * @param  array  $attributes
     * @return void
     */
    public function __construct(string $contents = '', array $attributes = [])
    {
        $this->contents = $contents;

        $this->withAttributes($attributes);
    }

    /**
     * Set the extra attributes that the slot should make available.
     *
     * @param  array  $attributes
     * @return $this
     */
    public function withAttributes(array $attributes): static
    {
        $this->attributes = new ComponentAttributeBag($attributes);

        return $this;
    }

    /**
     * Get the slot's HTML string.
     *
     * @return string
     */
    public function toHtml(): string
    {
        return $this->contents;
    }

    /**
     * Determine if the slot is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->contents === '';
    }

    /**
     * Determine if the slot is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Determine if the slot has non-comment content.
     *
     * @param  callable|string|null  $callable
     * @return bool
     */
    public function hasActualContent(callable|string|null $callable = null): bool
    {
        if (is_string($callable) && ! function_exists($callable)) {
            throw new InvalidArgumentException('Callable does not exist.');
        }

        return filter_var(
            $this->contents,
            FILTER_CALLBACK,
            ['options' => $callable ?? fn ($input) => trim(preg_replace("/<!--([\s\S]*?)-->/", '', $input))]
        ) !== '';
    }

    /**
     * Get the slot's HTML string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }
}
