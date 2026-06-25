<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TestHelper
{
    /**
     * Test the middleware response code.
     */
    public function testMiddleware(object $middleware, mixed $parameter): int
    {
        try {
            return $middleware->handle(new Request, function () {
                return (new Response)->setContent('<html></html>');
            }, $parameter)->status();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }
}
