<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 */
class AlternateLinks extends GroupedTagBuilder
{
    /**
     * Create the localized alternate links.
     *
     * @param array<string, string> $links
     */
    public function __construct(protected array $links = [])
    {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'alternates';
    }

    /**
     * Get the key used in the head array.
     */
    public static function headArrayKey(): string
    {
        return 'links.alternates';
    }

    /**
     * Build alternate links from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'alternates' || ! is_array($value)) {
            return null;
        }

        $links = new self;

        foreach ($value as $locale => $href) {
            if (is_string($href)) {
                $links->link((string) $locale, $href);
            }
        }

        return $links;
    }

    /**
     * Set the alternate link for a locale.
     */
    public function link(string $locale, string $href): static
    {
        $this->links[$locale] = $href;

        return $this;
    }

    /**
     * Overlay these locales onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(array_replace($base->links, $this->links));
    }

    /**
     * Determine whether no alternate links are defined.
     */
    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    /**
     * Get the alternate links as an array.
     *
     * @return array<string, string>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->links;
    }

    /**
     * Render the localized alternate link tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(
            fn (string $hreflang, string $href): string => $tags->linkWithAttributes('alternate', ['hreflang' => $hreflang, 'href' => $href], 'alternate:' . $hreflang),
            array_keys($this->links),
            $this->links,
        );
    }
}
