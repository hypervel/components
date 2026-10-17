<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Pagination;

use Hypervel\Support\Collection;
use InvalidArgumentException;

class CursorPaginator
{
    /**
     * Create a paginator for the supplied items.
     *
     * @param Collection<int, mixed> $items
     */
    public function __construct(protected Collection $items, protected int $perPage = 10, protected ?string $cursor = null)
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('The page size must be a positive integer.');
        }

        $this->items = $items->values();
    }

    /**
     * Return the current page and its continuation cursor.
     *
     * @return array<string, mixed>
     */
    public function paginate(string $key = 'items'): array
    {
        $startOffset = $this->getStartOffsetFromCursor();

        $paginatedItems = $this->items->slice($startOffset, $this->perPage);

        $hasMorePages = $this->items->count() > ($startOffset + $this->perPage);

        $result = [$key => $paginatedItems->values()->toArray()];

        if ($hasMorePages) {
            $result['nextCursor'] = $this->createCursor($startOffset + $this->perPage);
        }

        return $result;
    }

    /**
     * Decode the starting offset from the cursor.
     */
    protected function getStartOffsetFromCursor(): int
    {
        if (! is_string($this->cursor)) {
            return 0;
        }

        $decodedCursor = base64_decode($this->cursor, true);

        if ($decodedCursor === false) {
            return 0;
        }

        $cursorData = json_decode($decodedCursor, true);

        if (! is_array($cursorData)) {
            return 0;
        }

        return max(0, (int) ($cursorData['offset'] ?? 0));
    }

    /**
     * Encode a continuation offset.
     */
    protected function createCursor(int $offset): string
    {
        $cursorData = ['offset' => $offset];

        return base64_encode((string) json_encode($cursorData));
    }
}
