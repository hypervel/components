<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Connectors;

use Hypervel\Saloon\Http\Connector;

class NullConnector extends Connector
{
    /**
     * Resolve the empty base URL.
     */
    public function resolveBaseUrl(): string
    {
        return '';
    }
}
