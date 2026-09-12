<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;

/**
 * @template TItem
 * @extends Paginator<TItem>
 */
abstract class PagedPaginator extends Paginator
{
    /**
     * The page-number query parameter.
     */
    protected string $pageName = 'page';

    /**
     * The per-page limit query parameter.
     */
    protected string $perPageName = 'per_page';

    /**
     * Apply page-number pagination to the request.
     *
     * @param Request<mixed> $request
     * @return Request<mixed>
     */
    protected function applyPagination(Request $request): Request
    {
        $request->withQueryParameters([$this->pageName => $this->pageNumber]);

        if ($this->perPageLimit !== null) {
            $request->withQueryParameters([$this->perPageName => $this->perPageLimit]);
        }

        return $request;
    }
}
