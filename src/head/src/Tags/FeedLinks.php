<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type FeedAttributes array{href: string, title: string, type: string}
 */
class FeedLinks extends GroupedTagBuilder
{
    /**
     * Create the feed links.
     *
     * @param array<string, FeedAttributes> $feeds
     */
    public function __construct(protected array $feeds = [])
    {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'feeds';
    }

    /**
     * Get the key used in the head array.
     */
    public static function headArrayKey(): string
    {
        return 'links.feeds';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['feed'];
    }

    /**
     * Build feed links from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'feed' || ! is_array($value)) {
            return null;
        }

        $links = new self;

        foreach (self::feedAttributes($value) as $href => $feed) {
            if (! $links->addRouteAttributeFeed($href, $feed)) {
                return null;
            }
        }

        return $links;
    }

    /**
     * Add or replace a feed link by URL.
     */
    public function feed(string $href, string $title, string $type = 'rss'): static
    {
        $this->feeds[$href] = [
            'href' => $href,
            'title' => $title,
            'type' => $type,
        ];

        return $this;
    }

    /**
     * Overlay these feeds onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(array_replace($base->feeds, $this->feeds));
    }

    /**
     * Determine whether no feeds are defined.
     */
    public function isEmpty(): bool
    {
        return $this->feeds === [];
    }

    /**
     * Get the feed links for the head array.
     *
     * @return array<int, FeedAttributes>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->headArray();
    }

    /**
     * Render the feed link tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(function (array $feed) use ($tags): string {
            $type = $feed['type'] === 'atom' ? 'application/atom+xml' : 'application/rss+xml';

            return $tags->linkWithAttributes('alternate', [
                'type' => $type,
                'title' => $feed['title'],
                'href' => $feed['href'],
            ], $tags->stableKey('feed', $feed['href']));
        }, $this->headArray());
    }

    /**
     * Get the ordered feed definitions.
     *
     * @return array<int, FeedAttributes>
     */
    protected function headArray(): array
    {
        return array_values($this->feeds);
    }

    /**
     * Add a valid feed definition from route metadata.
     */
    private function addRouteAttributeFeed(mixed $href, mixed $feed): bool
    {
        if (is_string($href) && is_string($feed)) {
            $this->feed($href, $feed);

            return true;
        }

        if (! is_array($feed) || ! is_string($feed['title'] ?? null) || is_null($url = self::routeAttributeHref($href, $feed))) {
            return false;
        }

        $this->feed($url, $feed['title'], self::string($feed['type'] ?? null) ?? 'rss');

        return true;
    }

    /**
     * Normalize a route attribute value into a list of feed definitions,
     * treating an attribute map containing any single-feed key as one feed.
     *
     * @param array<mixed, mixed> $value
     * @return array<mixed, mixed>
     */
    private static function feedAttributes(array $value): array
    {
        if (array_is_list($value)) {
            return $value;
        }

        if (array_key_exists('href', $value) || array_key_exists('title', $value) || array_key_exists('type', $value)) {
            return [$value];
        }

        return $value;
    }

    /**
     * Resolve a feed URL from an explicit attribute or its map key.
     *
     * @param array<mixed, mixed> $feed
     */
    private static function routeAttributeHref(mixed $href, array $feed): ?string
    {
        return self::string($feed['href'] ?? null) ?? self::string($href);
    }
}
