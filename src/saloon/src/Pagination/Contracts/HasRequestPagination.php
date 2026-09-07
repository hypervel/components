<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Pagination\Paginator;

interface HasRequestPagination
{
    /**
     * Paginate through a connector.
     */
    public function paginate(Connector $connector): Paginator;
}
