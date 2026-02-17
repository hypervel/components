<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler\Stub;

use Hypervel\Context\Context;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class BarExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        Context::set('test.exception-handler.latest-handler', static::class);

        if ($throwable->getCode() === 0) {
            $this->stopPropagation();
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
