<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Http\Connectors\NullConnector;
use Hypervel\Saloon\Traits\Request\HasConnector;

/**
 * @template TDto
 * @extends Request<TDto>
 */
abstract class SoloRequest extends Request
{
    /** @use HasConnector<TDto> */
    use HasConnector;

    /**
     * Resolve whether this request may use an absolute endpoint.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return true;
    }

    /**
     * Create the connector used by the standalone request.
     */
    protected function resolveConnector(): Connector
    {
        return new NullConnector;
    }
}
