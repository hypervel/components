<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Responses;

use Hypervel\Saloon\Http\Response;

trait HasCustomResponses
{
    /**
     * The default response class.
     *
     * @var null|class-string<Response>
     */
    protected ?string $response = null;

    /**
     * Resolve the custom response class.
     *
     * @return null|class-string<Response>
     */
    public function resolveResponseClass(Response $response): ?string
    {
        return $this->response;
    }
}
