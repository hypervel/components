<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler\Stubs;

use Hypervel\Context\Context;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FooExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, Response $response)
    {
        Context::set('test.exception-handler.latest-handler', static::class);
        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
