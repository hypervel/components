<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage;

use Hypervel\HttpMessage\Server\Request\Parser;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use Hypervel\Support\ServiceProvider;

class HttpMessageServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(RequestParserInterface::class, Parser::class);
    }
}
