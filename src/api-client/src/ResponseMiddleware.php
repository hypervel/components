<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Psr\Http\Message\ResponseInterface;

abstract class ResponseMiddleware
{
    public function __invoke(ResponseInterface $response): ResponseInterface
    {
        return $this->handle(new ApiResponse($response))
            ->toPsrResponse();
    }

    abstract public function handle(ApiResponse $response): ApiResponse;
}
