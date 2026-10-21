<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use BackedEnum;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type LinkAttributes array{rel: string, href: string, attributes: array<string, bool|float|int|string|null>}
 */
class GenericLinks extends GroupedTagBuilder
{
    /**
     * Create the link definitions.
     *
     * @param array<string, LinkAttributes> $links
     */
    public function __construct(protected array $links = [])
    {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'links';
    }

    /**
     * Get the key used in the head array.
     */
    public static function headArrayKey(): string
    {
        return 'links.generic';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['link', 'icon', 'favicon', 'appleTouchIcon', 'maskIcon', 'manifest', 'appleTouchStartupImage'];
    }

    /**
     * Build links from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'link') {
            return self::fromAliasRouteAttribute($key, $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $links = new self;

        foreach (self::items($value) as $link) {
            $links->addRouteAttributeLink($link);
        }

        return $links;
    }

    /**
     * Build links from a named link alias.
     */
    protected static function fromAliasRouteAttribute(string $key, mixed $value): ?self
    {
        if (! in_array($key, ['icon', 'favicon', 'appleTouchIcon', 'maskIcon', 'manifest', 'appleTouchStartupImage'], true)) {
            return null;
        }

        $links = new self;

        foreach (self::items($value) as $link) {
            $links->addAliasRouteAttributeLink($key, $link);
        }

        return $links;
    }

    /**
     * Add or replace a link definition.
     *
     * @param array<string, null|BackedEnum|bool|float|int|string> $attributes
     */
    public function link(string $rel, string $href, array $attributes = []): static
    {
        unset($attributes['rel'], $attributes['href']);
        $attributes = self::attributes($attributes);

        // A document has one manifest; other links may coexist at different URLs.
        $key = $rel === 'manifest' ? $rel : $rel . ' ' . $href;

        $this->links[$key] = [
            'rel' => $rel,
            'href' => $href,
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * Overlay these links onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(array_replace($base->links, $this->links));
    }

    /**
     * Determine whether no links are defined.
     */
    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    /**
     * Get the links for the head array.
     *
     * @return array<int, LinkAttributes>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->headArray();
    }

    /**
     * Render the link tags with stable ownership keys.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(function (array $link) use ($tags): string {
            // Keep the manifest's identity when its URL changes during Inertia navigation.
            $key = $link['rel'] === 'manifest' ? 'link:manifest' : $tags->stableKey('link:' . $link['rel'], $link['href']);

            return $tags->linkWithAttributes($link['rel'], ['href' => $link['href'], ...$link['attributes']], $key);
        }, $this->headArray());
    }

    /**
     * Get the ordered link definitions.
     *
     * @return array<int, LinkAttributes>
     */
    protected function headArray(): array
    {
        return array_values($this->links);
    }

    /**
     * Add a valid link from route metadata.
     */
    private function addRouteAttributeLink(mixed $link): void
    {
        if (! is_array($link) || ! is_string($link['rel'] ?? null) || ! is_string($link['href'] ?? null)) {
            return;
        }

        $attributes = self::named($link);
        unset($attributes['rel'], $attributes['href']);

        $this->link($link['rel'], $link['href'], self::attributes($attributes));
    }

    /**
     * Add a link alias from route metadata.
     */
    private function addAliasRouteAttributeLink(string $key, mixed $link): void
    {
        $attributes = is_array($link) ? self::named($link) : [];
        $href = self::routeAttributeHref($link, $attributes, $key === 'manifest' ? '/site.webmanifest' : null);

        if (is_null($href)) {
            return;
        }

        match ($key) {
            'icon', 'favicon' => $this->link('icon', $href, self::attributes([
                'type' => self::stringOrBackedEnum($attributes['type'] ?? null),
                'sizes' => $attributes['sizes'] ?? null,
                'media' => $attributes['media'] ?? null,
            ])),
            'appleTouchIcon' => $this->link('apple-touch-icon', $href, self::attributes([
                'sizes' => $attributes['sizes'] ?? null,
            ])),
            'maskIcon' => $this->link('mask-icon', $href, self::attributes([
                'color' => $attributes['color'] ?? null,
            ])),
            'manifest' => $this->link('manifest', $href, self::attributes([
                'crossorigin' => $attributes['crossorigin'] ?? null,
            ])),
            'appleTouchStartupImage' => $this->link('apple-touch-startup-image', $href, self::attributes([
                'media' => $attributes['media'] ?? null,
            ])),
            default => null,
        };
    }

    /**
     * Resolve a link URL from its route attribute value.
     *
     * @param array<string, mixed> $attributes
     */
    private static function routeAttributeHref(mixed $value, array $attributes, ?string $default = null): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_string($attributes['href'] ?? null)) {
            return $attributes['href'];
        }

        if ($value === true || is_array($value)) {
            return $default;
        }

        return null;
    }

    /**
     * Normalize scalar and enum link attributes.
     *
     * @param array<string, mixed> $values
     * @return array<string, null|bool|float|int|string>
     */
    protected static function attributes(array $values): array
    {
        $attributes = [];

        foreach ($values as $key => $value) {
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if (is_null($value) || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }
}
