<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Pagination\Paginator;

/** @template TItem */
interface HasPagination
{
    /**
     * Paginate a request.
     *
     * @param Request<mixed> $request
     * @return Paginator<TItem>
     */
    public function paginate(Request $request): Paginator;
}
