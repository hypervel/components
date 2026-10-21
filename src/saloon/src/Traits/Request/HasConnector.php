<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Request;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;

/** @template TDto */
trait HasConnector
{
    /**
     * The loaded connector used in requests.
     */
    private ?Connector $loadedConnector = null;

    /**
     * Retrieve the loaded connector.
     */
    public function connector(): Connector
    {
        return $this->loadedConnector ??= $this->resolveConnector();
    }

    /**
     * Set the loaded connector at runtime.
     *
     * @return $this
     */
    public function setConnector(Connector $connector): static
    {
        $this->loadedConnector = $connector;

        return $this;
    }

    /**
     * Create a new connector instance.
     */
    protected function resolveConnector(): Connector
    {
        return new $this->connector;
    }

    /**
     * Prepare the request for sending through its connector.
     *
     * @return PendingRequest<TDto>
     */
    public function createPendingRequest(): PendingRequest
    {
        return $this->connector()->createPendingRequest($this);
    }

    /**
     * Send the request through its connector.
     *
     * @return Response<TDto>
     */
    public function send(?MockClient $mockClient = null): Response
    {
        return $this->connector()->send($this, $mockClient);
    }
}
