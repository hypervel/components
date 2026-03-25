<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware;

use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @internal
 * @coversNothing
 */
class ConvertEmptyStringsToNullTest extends TestCase
{
    public function testConvertsEmptyStringsToNull()
    {
        $middleware = new ConvertEmptyStringsToNull();
        $symfonyRequest = new SymfonyRequest([
            'foo' => 'bar',
            'baz' => '',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('bar', $request->get('foo'));
            $this->assertNull($request->get('baz'));
        });
    }

    public function testSkipConvertsEmptyStringsToNull()
    {
        $middleware = new ConvertEmptyStringsToNull();
        ConvertEmptyStringsToNull::skipWhen(fn ($request) => $request->baz === '');
        $symfonyRequest = new SymfonyRequest([
            'foo' => 'bar',
            'baz' => '',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('bar', $request->get('foo'));
            $this->assertSame('', $request->get('baz'));
        });
    }
}
