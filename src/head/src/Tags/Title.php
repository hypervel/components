<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 */
class Title extends TagBuilder
{
    /**
     * Create a title definition with optional decoration.
     */
    public function __construct(
        protected ?string $value = null,
        protected ?bool $exact = null,
        protected ?string $prefix = null,
        protected ?string $suffix = null,
    ) {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'title';
    }

    /**
     * Create a title with inherited decoration unless exact rendering is requested.
     */
    public static function make(string $value, ?string $prefix = null, ?string $suffix = null, ?bool $exact = null): self
    {
        return new self($value, $exact ?? false, $prefix, $suffix);
    }

    /**
     * Build a title from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'title') {
            return null;
        }

        $title = $value;

        if (is_string($title)) {
            return self::make($title);
        }

        if (! is_array($title) || ! is_string($title['value'] ?? null)) {
            return null;
        }

        return self::make(
            $title['value'],
            prefix: self::string($title['prefix'] ?? null),
            suffix: self::string($title['suffix'] ?? null),
            exact: self::bool($title['exact'] ?? null),
        );
    }

    /**
     * Overlay this title while inheriting unspecified decoration.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(
            $this->value ?? $base->value,
            $this->exact ?? $base->exact,
            $this->prefix ?? $base->prefix,
            $this->suffix ?? $base->suffix,
        );
    }

    /**
     * Default titles render exactly; the prefix and suffix only decorate page titles.
     */
    public function asDefaults(): static
    {
        return is_null($this->value)
            ? $this
            : new static($this->value, true, $this->prefix, $this->suffix);
    }

    /**
     * Determine whether no title value or options are defined.
     */
    public function isEmpty(): bool
    {
        return is_null($this->value)
            && is_null($this->exact)
            && is_null($this->prefix)
            && is_null($this->suffix);
    }

    /**
     * Render the title with its effective decoration.
     */
    public function render(): ?string
    {
        if (is_null($this->value)) {
            return null;
        }

        if ($this->exact === true) {
            return $this->value;
        }

        return ($this->prefix ?? '') . $this->value . ($this->suffix ?? '');
    }

    /**
     * Get the rendered title for the head array.
     */
    public function toHeadArray(ResolvedHead $head): ?string
    {
        return $this->render();
    }

    /**
     * Render the title tag.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        $title = $this->render();

        return $title !== null && $title !== '' ? [$tags->title($title, 'title')] : [];
    }
}
