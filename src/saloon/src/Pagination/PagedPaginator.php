<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;

abstract class PagedPaginator extends Paginator
{
    /**
     * Apply page-number pagination to the request.
     */
    protected function applyPagination(Request $request): Request
    {
        $request->withQueryParameters(['page' => $this->pageNumber]);

        if ($this->perPageLimit !== null) {
            $request->withQueryParameters(['per_page' => $this->perPageLimit]);
        }

        return $request;
    }
}
