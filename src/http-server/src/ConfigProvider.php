<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\HttpServer\Contracts\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                RequestInterface::class => Request::class,
                ResponseInterface::class => Response::class,
                ServerRequestInterface::class => Request::class,
            ],
        ];
    }
}
