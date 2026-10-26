<?php

declare(strict_types=1);

namespace Hypervel\Head\Rendering;

use InvalidArgumentException;

class TagRenderer
{
    /**
     * Create the tag renderer.
     */
    public function __construct(protected bool $withInertiaAttributes = false)
    {
    }

    /**
     * Get a renderer that stamps tags with data-inertia ownership attributes.
     */
    public function withInertiaAttributes(): self
    {
        return new self(true);
    }

    /**
     * Render an escaped title tag.
     */
    public function title(string $title, ?string $inertiaKey = null): string
    {
        $inertiaKey ??= 'title';

        return $this->element('title', e($title), $this->inertiaAttributes($inertiaKey));
    }

    /**
     * Render a meta tag keyed by the given attribute ("name" or "property").
     */
    public function meta(string $attribute, string $key, string|int|float|bool $content, ?string $inertiaKey = null): string
    {
        $inertiaKey ??= $key;

        return $this->metaWithAttributes($attribute, $key, ['content' => (string) $content], $inertiaKey);
    }

    /**
     * Render a meta tag keyed by the given attribute ("name" or "property") with arbitrary attributes.
     *
     * @param array<string, null|bool|float|int|string> $attributes
     */
    public function metaWithAttributes(string $attribute, string $key, array $attributes, ?string $inertiaKey = null): string
    {
        $inertiaKey ??= $key;

        return $this->voidElement(
            'meta',
            $this->inertiaAttributes($inertiaKey),
            [$attribute => $key],
            $attributes,
        );
    }

    /**
     * Render a link tag.
     */
    public function link(string $rel, string $href, ?string $inertiaKey = null): string
    {
        $inertiaKey ??= $this->stableKey($rel, $href);

        return $this->voidElement(
            'link',
            $this->inertiaAttributes($inertiaKey),
            ['rel' => $rel, 'href' => $href],
        );
    }

    /**
     * Render a link tag with arbitrary attributes.
     *
     * @param array<string, null|bool|float|int|string> $attributes
     */
    public function linkWithAttributes(string $rel, array $attributes, ?string $inertiaKey = null): string
    {
        $inertiaKey ??= $this->stableKey($rel, (string) ($attributes['href'] ?? serialize($attributes)));

        return $this->voidElement(
            'link',
            $this->inertiaAttributes($inertiaKey),
            ['rel' => $rel],
            $attributes,
        );
    }

    /**
     * Render structured data with script-safe JSON encoding.
     *
     * @param array<string, mixed> $schema
     */
    public function jsonLd(array $schema, ?string $inertiaKey = null): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $inertiaKey ??= $this->stableKey('schema', $json);

        return $this->element(
            'script',
            $json,
            $this->inertiaAttributes($inertiaKey),
            ['type' => 'application/ld+json'],
        );
    }

    /**
     * Render escaped HTML attributes.
     *
     * @param array<string, null|bool|float|int|string> $attributes
     */
    public function attributes(array $attributes): string
    {
        return $this->renderAttributes($attributes);
    }

    /**
     * Attribute names aren't HTML-escaped, so reject anything that could
     * break out of the attribute position. The "u" modifier also makes
     * invalid UTF-8 fail the match.
     */
    protected function isValidAttributeName(string $name): bool
    {
        return preg_match('/^[^\x00-\x20\x7F-\x9F"\'\/=>]+$/u', $name) === 1;
    }

    /**
     * Generate a stable, content-derived data-inertia key for a tag.
     */
    public function stableKey(string $prefix, string $value): string
    {
        return $prefix . ':' . substr(hash('xxh128', $value), 0, 16);
    }

    /**
     * Validate and escape the combined attribute sets.
     *
     * @param array<int|string, null|bool|float|int|string> ...$attributeSets
     */
    protected function renderAttributes(array ...$attributeSets): string
    {
        $rendered = [];
        $seen = [];

        foreach ($attributeSets as $attributes) {
            foreach ($attributes as $name => $value) {
                if ($value === false || is_null($value)) {
                    continue;
                }

                $name = (string) $name;

                if (! $this->isValidAttributeName($name)) {
                    throw new InvalidArgumentException('Invalid HTML attribute name.');
                }

                $normalizedName = strtolower($name);

                if (isset($seen[$normalizedName])) {
                    throw new InvalidArgumentException("Duplicate HTML attribute name [{$name}].");
                }

                $seen[$normalizedName] = true;
                $rendered[] = $value === true
                    ? $name
                    : $name . '="' . e((string) $value) . '"';
            }
        }

        return implode(' ', $rendered);
    }

    /**
     * Render a void HTML element.
     *
     * @param array<int|string, null|bool|float|int|string> ...$attributeSets
     */
    protected function voidElement(string $name, array ...$attributeSets): string
    {
        return '<' . $name . $this->renderedAttributes(...$attributeSets) . '>';
    }

    /**
     * Render an HTML element with content.
     *
     * @param array<int|string, null|bool|float|int|string> ...$attributeSets
     */
    protected function element(string $name, string $content, array ...$attributeSets): string
    {
        return '<' . $name . $this->renderedAttributes(...$attributeSets) . '>' . $content . '</' . $name . '>';
    }

    /**
     * Render attributes with their leading separator.
     *
     * @param array<int|string, null|bool|float|int|string> ...$attributeSets
     */
    protected function renderedAttributes(array ...$attributeSets): string
    {
        $attributes = $this->renderAttributes(...$attributeSets);

        return $attributes === '' ? '' : ' ' . $attributes;
    }

    /**
     * Get the ownership attribute when Inertia rendering is enabled.
     *
     * @return array<string, string>
     */
    protected function inertiaAttributes(?string $key): array
    {
        return $this->withInertiaAttributes && ! is_null($key)
            ? ['data-inertia' => $key]
            : [];
    }
}
