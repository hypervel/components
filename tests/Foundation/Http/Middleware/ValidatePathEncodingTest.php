<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware;

use Hypervel\Http\Exceptions\MalformedUrlException;
use Hypervel\Http\Middleware\ValidatePathEncoding;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @internal
 * @coversNothing
 */
class ValidatePathEncodingTest extends TestCase
{
    #[TestWith(['/'])]
    #[TestWith(['valid-path'])]
    #[TestWith(['ä'])]
    #[TestWith(['with%20space'])]
    #[TestWith(['汉字字符集'])]
    public function testValidPathsArePassing(string $path)
    {
        $middleware = new ValidatePathEncoding;
        $symfonyRequest = new SymfonyRequest;
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $symfonyRequest->server->set('REQUEST_URI', $path);
        $request = Request::createFromBase($symfonyRequest);

        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame(200, $response->status());
        $this->assertSame('OK', $response->content());
    }

    #[TestWith(['%C0'])]
    #[TestWith(['%c0'])]
    public function testInvalidPathsAreFailing(string $path)
    {
        $middleware = new ValidatePathEncoding;
        $symfonyRequest = new SymfonyRequest;
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $symfonyRequest->server->set('REQUEST_URI', $path);
        $request = Request::createFromBase($symfonyRequest);

        try {
            $middleware->handle($request, fn () => new Response('OK'));

            $this->fail('MalformedUrlExceptions should have been thrown.');
        } catch (MalformedUrlException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Malformed URL.', $e->getMessage());
        }
    }
}
