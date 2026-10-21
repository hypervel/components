<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\HasPagination;
use Hypervel\Saloon\Pagination\Contracts\HasRequestPagination;
use Hypervel\Saloon\Pagination\CursorPaginator;
use Hypervel\Saloon\Pagination\Paginator;

class CursorConnector extends TestConnector implements HasPagination
{
    /**
     * Paginate over each page.
     */
    public function paginate(Request $request): Paginator
    {
        if ($request instanceof HasRequestPagination) {
            return $request->paginate($this);
        }

        return new class(connector: $this, request: $request) extends CursorPaginator {
            /**
             * Get the next cursor.
             */
            protected function getNextCursor(Response $response): int|string
            {
                $nextPageUrl = $response->json('next_page_url');
                parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $queryParams);

                return $queryParams['cursor'];
            }

            /**
             * Check if we are on the last page.
             */
            protected function isLastPage(Response $response): bool
            {
                return empty($response->json('next_page_url'));
            }

            /**
             * Get the results from the page.
             */
            protected function getPageItems(Response $response, Request $request): array
            {
                return $response->json('data') ?? [];
            }
        };
    }
}
