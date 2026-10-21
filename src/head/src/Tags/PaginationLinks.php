<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Contracts\Pagination\CursorPaginator;
use Hypervel\Contracts\Pagination\Paginator;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

/**
 * @phpstan-consistent-constructor
 */
class PaginationLinks extends GroupedTagBuilder
{
    /**
     * Create the pagination links.
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
        return 'pagination';
    }

    /**
     * Get the key used in the head array.
     */
    public static function headArrayKey(): string
    {
        return 'links.pagination';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return [];
    }

    /**
     * Create previous and next links from a paginator.
     *
     * @param CursorPaginator<int, mixed>|Paginator<int, mixed> $paginator
     */
    public static function fromPaginator(Paginator|CursorPaginator $paginator): self
    {
        $links = new self;

        if ($previous = $paginator->previousPageUrl()) {
            $links->link('prev', $previous);
        }

        if ($next = $paginator->nextPageUrl()) {
            $links->link('next', $next);
        }

        return $links;
    }

    /**
     * Set a pagination link by relation.
     */
    public function link(string $rel, string $href): static
    {
        $this->links[$rel] = $href;

        return $this;
    }

    /**
     * Replace the lower layer's pagination links.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        // Pagination describes one page; merging would retain relations that no longer exist.
        return $this;
    }

    /**
     * Determine whether no pagination links are defined.
     */
    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    /**
     * Get the pagination links for the head array.
     *
     * @return array<string, string>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return $this->links;
    }

    /**
     * Render the pagination link tags.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(
            fn (string $rel, string $href): string => $tags->link($rel, $href, 'pagination:' . $rel),
            array_keys($this->links),
            $this->links,
        );
    }
}
