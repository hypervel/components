<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Contracts;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

interface CoreMiddlewareInterface extends MiddlewareInterface
{
    public function dispatch(ServerRequestInterface $request): ServerRequestInterface;
}
