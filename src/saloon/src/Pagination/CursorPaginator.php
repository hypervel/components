<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use LogicException;
use Throwable;

/**
 * @template TItem
 * @extends Paginator<TItem>
 */
abstract class CursorPaginator extends Paginator
{
    /**
     * The cursor query parameter.
     */
    protected string $cursorName = 'cursor';

    /**
     * The per-page limit query parameter.
     */
    protected string $perPageName = 'per_page';

    /**
     * Apply cursor pagination to the request.
     *
     * @param Request<mixed> $request
     * @return Request<mixed>
     */
    protected function applyPagination(Request $request): Request
    {
        if ($this->currentResponse instanceof Response) {
            $request->withQueryParameters([$this->cursorName => $this->getNextCursor($this->currentResponse)]);
        }

        if ($this->perPageLimit !== null) {
            $request->withQueryParameters([$this->perPageName => $this->perPageLimit]);
        }

        return $request;
    }

    /**
     * Get the next cursor.
     *
     * @param Response<mixed> $response
     */
    abstract protected function getNextCursor(Response $response): int|string;

    /**
     * Reject pooled cursor pagination because later cursors depend on earlier responses.
     *
     * @param null|callable(Response<mixed>, int): void $responseHandler
     * @param null|callable(Throwable, int): void $exceptionHandler
     * @return array<int, Response<mixed>>
     */
    public function pool(
        int $concurrency = 5,
        ?callable $responseHandler = null,
        ?callable $exceptionHandler = null,
    ): array {
        throw new LogicException('Cursor pagination must be processed sequentially because each cursor comes from the previous response.');
    }
}
