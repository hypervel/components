<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use LogicException;
use Throwable;

abstract class CursorPaginator extends Paginator
{
    /**
     * Apply cursor pagination to the request.
     */
    protected function applyPagination(Request $request): Request
    {
        if ($this->currentResponse instanceof Response) {
            $request->withQueryParameters(['cursor' => $this->getNextCursor($this->currentResponse)]);
        }

        if ($this->perPageLimit !== null) {
            $request->withQueryParameters(['per_page' => $this->perPageLimit]);
        }

        return $request;
    }

    /**
     * Get the next cursor.
     */
    abstract protected function getNextCursor(Response $response): int|string;

    /**
     * Reject pooled cursor pagination because later cursors depend on earlier responses.
     *
     * @param null|callable(Response, int): void $responseHandler
     * @param null|callable(Throwable, int): void $exceptionHandler
     * @return array<int, Response>
     */
    public function pool(
        int $concurrency = 5,
        ?callable $responseHandler = null,
        ?callable $exceptionHandler = null,
    ): array {
        throw new LogicException('Cursor pagination must be processed sequentially because each cursor comes from the previous response.');
    }
}
