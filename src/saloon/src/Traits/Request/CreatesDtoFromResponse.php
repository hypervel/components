<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Request;

use Hypervel\Saloon\Http\Response;

/** @template TDto */
trait CreatesDtoFromResponse
{
    /**
     * Create a data object from the response.
     *
     * @return TDto
     */
    public function createDtoFromResponse(Response $response): mixed
    {
        return null;
    }
}
