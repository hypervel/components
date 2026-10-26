<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Enums\Media;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Support\Str;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type MetaAttributes array{key: string, content: string, property?: bool|null, media?: string|null}
 */
class MetaTags extends GroupedTagBuilder
{
    /**
     * Create the meta tag definitions.
     *
     * @param array<string, MetaAttributes> $tags
     */
    public function __construct(protected array $tags = [])
    {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'meta';
    }

    /**
     * Build meta tags from a route attribute or alias.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key === 'webAppCapable' && is_bool($value)) {
            return (new self)->tag(self::aliases()[$key], $value ? 'yes' : 'no');
        }

        if (array_key_exists($key, self::aliases()) && is_string($value)) {
            return (new self)->tag(self::aliases()[$key], $value);
        }

        if ($key !== 'meta' || ! is_array($value)) {
            return null;
        }

        $tags = new self;

        foreach ($value as $metaKey => $meta) {
            if (is_string($metaKey) && is_string($meta)) {
                $tags->tag($metaKey, $meta);
            }

            if (is_array($meta) && is_string($meta['key'] ?? null) && is_string($meta['content'] ?? null)) {
                $tags->tag(
                    $meta['key'],
                    $meta['content'],
                    property: self::bool($meta['property'] ?? null),
                    media: self::stringOrBackedEnum($meta['media'] ?? null),
                );
            }
        }

        return $tags;
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['meta', ...array_keys(self::aliases())];
    }

    /**
     * Add or replace a meta tag for its attribute, key, and media.
     */
    public function tag(string $key, string $content, ?bool $property = null, Media|string|null $media = null): static
    {
        $media = $media instanceof Media ? $media->value : $media;

        $this->tags[$this->tagKey($key, $property, $media)] = array_filter([
            'key' => $key,
            'content' => $content,
            'property' => $property,
            'media' => $media,
        ], fn (bool|string|null $value): bool => ! is_null($value));

        return $this;
    }

    /**
     * Overlay these meta tags onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(array_replace($base->tags, $this->tags));
    }

    /**
     * Determine whether no meta tags are defined.
     */
    public function isEmpty(): bool
    {
        return $this->tags === [];
    }

    /**
     * Get the meta tags for the head array.
     *
     * @return array<int, MetaAttributes>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->headArray();
    }

    /**
     * Render the meta tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(function (array $meta) use ($tags): string {
            $attribute = $this->resolveAttribute($meta['key'], $meta['property'] ?? null);

            return $tags->metaWithAttributes($attribute, $meta['key'], [
                'content' => $meta['content'],
                'media' => $meta['media'] ?? null,
            ], $this->inertiaKey($meta));
        }, $this->headArray());
    }

    /**
     * Get the ordered meta tag definitions.
     *
     * @return array<int, MetaAttributes>
     */
    protected function headArray(): array
    {
        return array_values($this->tags);
    }

    /**
     * Determine whether the key belongs to an RDFa namespace.
     */
    protected function isRdfaProperty(string $key): bool
    {
        return Str::startsWith($key, ['og:', 'article:', 'book:', 'profile:', 'music:', 'video:', 'fb:', 'product:']);
    }

    /**
     * Resolve the HTML attribute a meta tag is keyed under, honoring an explicit
     * property flag and falling back to RDFa namespace detection.
     */
    protected function resolveAttribute(string $key, ?bool $property): string
    {
        return ($property ?? $this->isRdfaProperty($key)) ? 'property' : 'name';
    }

    /**
     * Get the identity a meta tag is deduplicated by: attribute, key, and media query.
     */
    protected function tagKey(string $key, ?bool $property, ?string $media): string
    {
        return implode('|', [
            $this->resolveAttribute($key, $property),
            $key,
            $media ?? '',
        ]);
    }

    /**
     * Get the stable Inertia ownership key for a meta tag.
     *
     * @param MetaAttributes $meta
     */
    protected function inertiaKey(array $meta): string
    {
        if (! isset($meta['media'])
            && $this->resolveAttribute($meta['key'], $meta['property'] ?? null) === $this->resolveAttribute($meta['key'], null)) {
            return self::inertiaAliases()[$meta['key']] ?? $meta['key'];
        }

        return 'meta:' . substr(hash('xxh128', $this->tagKey($meta['key'], $meta['property'] ?? null, $meta['media'] ?? null)), 0, 16);
    }

    /**
     * Get the route attribute aliases for meta tag names.
     *
     * @return array<string, string>
     */
    protected static function aliases(): array
    {
        return [
            'themeColor' => 'theme-color',
            'applicationName' => 'application-name',
            'colorScheme' => 'color-scheme',
            'referrer' => 'referrer',
            'viewport' => 'viewport',
            'appleWebAppTitle' => 'apple-mobile-web-app-title',
            'webAppCapable' => 'mobile-web-app-capable',
            'appleWebAppStatusBarStyle' => 'apple-mobile-web-app-status-bar-style',
        ];
    }

    /**
     * Get the Inertia ownership aliases for meta tag names.
     *
     * @return array<string, string>
     */
    protected static function inertiaAliases(): array
    {
        return array_flip(self::aliases());
    }
}
