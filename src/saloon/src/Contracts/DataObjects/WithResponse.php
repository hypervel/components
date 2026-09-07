<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts\DataObjects;

use Hypervel\Saloon\Http\Response;

interface WithResponse
{
    /**
     * Set the response on the data object.
     *
     * @return $this
     */
    public function setResponse(Response $response): static;

    /**
     * Get the response on the data object.
     */
    public function getResponse(): Response;
}
