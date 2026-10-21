<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

/** @template TConnector of Connector<mixed> */
class BaseResource
{
    /**
     * Create a resource for the given connector.
     *
     * @param TConnector $connector
     */
    public function __construct(protected readonly Connector $connector)
    {
    }
}
