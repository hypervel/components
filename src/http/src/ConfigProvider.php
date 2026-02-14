<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\HttpServer\CoreMiddleware as HttpServerCoreMiddleware;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Psr\Http\Message\ServerRequestInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ResponseContract::class => Response::class,
                ServerRequestInterface::class => Request::class,
                HttpServerCoreMiddleware::class => CoreMiddleware::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of cors.',
                    'source' => __DIR__ . '/../publish/cors.php',
                    'destination' => BASE_PATH . '/config/autoload/cors.php',
                ],
            ],
        ];
    }
}
