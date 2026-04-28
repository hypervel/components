<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use InvalidArgumentException;

/**
 * @implements Arrayable<string, mixed>
 */
class ScrollMetadata implements Arrayable, ProvidesScrollMetadata
{
    /**
     * Create a new scroll metadata instance.
     */
    public function __construct(
        protected readonly string $pageName,
        protected readonly int|string|null $previousPage = null,
        protected readonly int|string|null $nextPage = null,
        protected readonly int|string|null $currentPage = null,
    ) {
    }

    /**
     * Create a scroll metadata instance from a paginator.
     */
    public static function fromPaginator(mixed $value): self
    {
        $paginator = $value instanceof JsonResource ? $value->resource : $value;

        if ($paginator instanceof CursorPaginator) {
            return new self(
                $cursorName = $paginator->getCursorName(),
                $paginator->previousCursor()?->encode(),
                $paginator->nextCursor()?->encode(),
                $paginator->onFirstPage() ? 1 : (CursorPaginator::resolveCurrentCursor($cursorName)?->encode() ?? 1)
            );
        }

        if ($paginator instanceof LengthAwarePaginator || $paginator instanceof Paginator) {
            return new self(
                $paginator->getPageName(),
                $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
                $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                $paginator->currentPage(),
            );
        }

        throw new InvalidArgumentException('The given value is not a Hypervel paginator instance. Use a custom callback to extract pagination metadata.');
    }

    /**
     * Get the page name parameter.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Get the previous page identifier.
     */
    public function getPreviousPage(): int|string|null
    {
        return $this->previousPage;
    }

    /**
     * Get the next page identifier.
     */
    public function getNextPage(): int|string|null
    {
        return $this->nextPage;
    }

    /**
     * Get the current page identifier.
     */
    public function getCurrentPage(): int|string|null
    {
        return $this->currentPage;
    }

    /**
     * Convert the scroll metadata instance to an array.
     */
    public function toArray(): array
    {
        return [
            'pageName' => $this->getPageName(),
            'previousPage' => $this->getPreviousPage(),
            'nextPage' => $this->getNextPage(),
            'currentPage' => $this->getCurrentPage(),
        ];
    }
}
