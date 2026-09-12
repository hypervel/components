<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Pagination\Paginator;

/** @template TItem */
interface HasRequestPagination
{
    /**
     * Paginate through a connector.
     *
     * @param Connector<mixed> $connector
     * @return Paginator<TItem>
     */
    public function paginate(Connector $connector): Paginator;
}
