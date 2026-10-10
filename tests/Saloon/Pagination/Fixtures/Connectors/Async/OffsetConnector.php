<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\Async;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\HasPagination;
use Hypervel\Saloon\Pagination\Contracts\HasRequestPagination;
use Hypervel\Saloon\Pagination\OffsetPaginator;
use Hypervel\Saloon\Pagination\Paginator;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\TestConnector;

class OffsetConnector extends TestConnector implements HasPagination
{
    /**
     * Paginate over each page.
     */
    public function paginate(Request $request): Paginator
    {
        if ($request instanceof HasRequestPagination) {
            return $request->paginate($this);
        }

        return new class(connector: $this, request: $request) extends OffsetPaginator {
            /**
             * Per Page.
             */
            protected ?int $perPageLimit = 5;

            /**
             * Check if we are on the last page.
             */
            protected function isLastPage(Response $response): bool
            {
                return $this->getOffset() >= (int) $response->json('total');
            }

            /**
             * Get the results from the page.
             */
            protected function getPageItems(Response $response, Request $request): array
            {
                return $response->json('data') ?? [];
            }

            /**
             * Get the total number of pages.
             */
            protected function getTotalPages(Response $response): int
            {
                return (int) ceil($response->json('total') / $this->perPageLimit);
            }
        };
    }
}
