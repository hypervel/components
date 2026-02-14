<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Stubs;

use Hypervel\Contracts\Container\Container;
use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\HttpServer\Contracts\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FakeMiddleware implements MiddlewareInterface
{
    protected Container $container;

    protected RequestInterface $request;

    protected HttpResponse $response;

    public function __construct(Container $container, HttpResponse $response, RequestInterface $request)
    {
        $this->container = $container;
        $this->response = $response;
        $this->request = $request;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
