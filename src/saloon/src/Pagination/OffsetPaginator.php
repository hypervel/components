<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;
use LogicException;

abstract class OffsetPaginator extends Paginator
{
    /**
     * Apply offset pagination to the request.
     */
    protected function applyPagination(Request $request): Request
    {
        if ($this->perPageLimit === null) {
            throw new LogicException('Define a per-page limit before using offset pagination.');
        }

        return $request->withQueryParameters([
            'limit' => $this->perPageLimit,
            'offset' => $this->getOffset(),
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
