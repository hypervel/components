<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Pagination\Paginator;

interface HasPagination
{
    /**
     * Paginate a request.
     */
    public function paginate(Request $request): Paginator;
}
