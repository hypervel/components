<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Psr\Http\Message\RequestInterface;

abstract class RequestMiddleware
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        return $this->handle(new ApiRequest($request))
            ->toPsrRequest();
    }

    abstract public function handle(ApiRequest $request): ApiRequest;
}
