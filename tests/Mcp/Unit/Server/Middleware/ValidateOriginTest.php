<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Middleware;

use Hypervel\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Mcp\Server\Middleware\ValidateOrigin;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ValidateOriginTest extends TestCase
{
    #[DataProvider('allowedOrigins')]
    public function testAllowsConfiguredOriginsAndNonBrowserClients(?string $origin): void
    {
        $request = Request::create('https://untrusted-host.test/mcp');

        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        $response = new Response('ok');

        $this->assertSame($response, $this->middleware()->handle($request, fn (): Response => $response));
    }

    /**
     * Provide allowed origins, including normalized default ports.
     */
    public static function allowedOrigins(): array
    {
        return [
            'no header' => [null],
            'application' => ['https://app.test'],
            'normalized application' => ['HTTPS://APP.TEST:443'],
            'additional origin' => ['http://client.test:8080'],
        ];
    }

    #[DataProvider('rejectedOrigins')]
    public function testRejectsOtherOriginsBeforeCallingTheHandler(string|array $origin): void
    {
        $request = Request::create('https://untrusted-host.test/mcp');
        $request->headers->set('Origin', $origin);

        $this->expectException(AccessDeniedHttpException::class);

        $this->middleware()->handle($request, function (): never {
            $this->fail('The request must be rejected before reaching the handler.');
        });
    }

    /**
     * Provide unapproved and malformed origins.
     */
    public static function rejectedOrigins(): array
    {
        return [
            'host header is not trusted' => ['https://untrusted-host.test'],
            'different port' => ['https://app.test:8443'],
            'different scheme' => ['http://app.test'],
            'opaque' => ['null'],
            'empty' => [''],
            'path' => ['https://app.test/path'],
            'credentials' => ['https://user@app.test'],
            'query' => ['https://app.test?query'],
            'fragment' => ['https://app.test#fragment'],
            'invalid port' => ['https://app.test:99999'],
            'multiple headers' => [['https://app.test', 'https://other.test']],
        ];
    }

    /**
     * Create middleware with the application's trusted origins.
     */
    private function middleware(): ValidateOrigin
    {
        return new ValidateOrigin(new Repository([
            'app' => ['url' => 'https://app.test/subdirectory'],
            'mcp' => ['allowed_origins' => ['http://client.test:8080']],
        ]));
    }
}
