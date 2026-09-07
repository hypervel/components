<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Http\Connectors\NullConnector;
use Hypervel\Saloon\Http\Faking\MockClient;

/** @template TDto */
abstract class SoloRequest extends Request
{
    /**
     * The connector used by the standalone request.
     */
    protected ?Connector $connector = null;

    /**
     * Get the connector used by the standalone request.
     */
    public function connector(): Connector
    {
        return $this->connector ??= $this->resolveConnector();
    }

    /**
     * Send the standalone request.
     *
     * @return Response<TDto>
     */
    public function send(?MockClient $mockClient = null): Response
    {
        return $this->connector()->send($this, $mockClient);
    }

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
