<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Fixtures;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Head\Tags\TagBuilder;

/**
 * @phpstan-consistent-constructor
 */
class ReadingTime extends TagBuilder
{
    /**
     * Create a reading-time definition.
     */
    public function __construct(protected ?int $minutes = null)
    {
    }

    /**
     * Get the custom builder key.
     */
    public static function key(): string
    {
        return 'readingTime';
    }

    /**
     * Create a reading-time value.
     */
    public static function make(int $minutes): self
    {
        return new self($minutes);
    }

    /**
     * Build a reading time from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        return $key === 'readingTime' && is_int($value) ? self::make($value) : null;
    }

    /**
     * Get the reading time for the head array.
     */
    public function toHeadArray(ResolvedHead $head): ?int
    {
        return $this->minutes;
    }

    /**
     * Render the reading-time meta tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return is_null($this->minutes)
            ? []
            : [$tags->meta('name', 'twitter:label1', 'Reading time'), $tags->meta('name', 'twitter:data1', $this->minutes . ' min read')];
    }

    /**
     * Overlay this reading time onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof static) {
            return $this;
        }

        return new static($this->minutes ?? $base->minutes);
    }

    /**
     * Determine whether no reading time is defined.
     */
    public function isEmpty(): bool
    {
        return is_null($this->minutes);
    }
}
