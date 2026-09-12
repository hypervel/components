<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;
use LogicException;

/**
 * @template TItem
 * @extends Paginator<TItem>
 */
abstract class OffsetPaginator extends Paginator
{
    /**
     * The result-limit query parameter.
     */
    protected string $limitName = 'limit';

    /**
     * The result-offset query parameter.
     */
    protected string $offsetName = 'offset';

    /**
     * Apply offset pagination to the request.
     *
     * @param Request<mixed> $request
     * @return Request<mixed>
     */
    protected function applyPagination(Request $request): Request
    {
        if ($this->perPageLimit === null) {
            throw new LogicException('Define a per-page limit before using offset pagination.');
        }

        return $request->withQueryParameters([
            $this->limitName => $this->perPageLimit,
            $this->offsetName => $this->getOffset(),
        ]);
    }

    /**
     * Get the current result offset.
     */
    protected function getOffset(): int
    {
        return ($this->pageNumber - 1) * $this->perPageLimit;
    }
}
