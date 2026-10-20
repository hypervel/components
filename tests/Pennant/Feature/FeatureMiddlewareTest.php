<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\Middleware\EnsureFeaturesAreActive;
use Hypervel\Tests\Pennant\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureMiddlewareTest extends TestCase
{
    use LazilyRefreshDatabase;

    private EnsureFeaturesAreActive $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new EnsureFeaturesAreActive;
    }

    public function testItThrowsAHttpExceptionIfFeatureIsNotDefined(): void
    {
        $this->expectException(HttpException::class);
        $this->assertFalse(Feature::active('test'));

        $this->middleware->handle(
            request: $this->createRequest('test', 'get'),
            next: fn () => new Response,
            features: 'test',
        );
    }

    public function testItPassesIfFeatureIsDefined(): void
    {
        Feature::define('test', true);

        $this->assertFalse(Feature::active('foo'));

        $this->assertEquals(
            Response::HTTP_OK,
            $this->middleware->handle(
                request: $this->createRequest('test', 'get'),
                next: fn () => new Response,
                features: 'test',
            )->getStatusCode(),
        );
    }

    public function testItThrowsAnExceptionIfOneOfTheFeaturesIsNotActive(): void
    {
        $this->expectException(HttpException::class);
        $this->assertFalse(Feature::active('test'));

        $this->middleware->handle(
            $this->createRequest('test', 'get'),
            fn () => new Response,
            'test',
            'another',
        );
    }

    public function testItAllowsCustomResponses(): void
    {
        $this->assertFalse(Feature::active('test'));

        EnsureFeaturesAreActive::whenInactive(fn (Request $request, array $features) => 'test-response');

        $response = $this->middleware->handle(
            $this->createRequest('test', 'get'),
            fn () => new Response,
            'test',
            'another',
        );

        $this->assertEquals('test-response', $response);

        EnsureFeaturesAreActive::whenInactive(null);
    }

    public function testItPassesIfAllFeaturesAreEnabled(): void
    {
        Feature::define('test', true);
        Feature::define('another', true);

        $this->assertFalse(Feature::active('foo'));

        $this->assertEquals(
            Response::HTTP_OK,
            $this->middleware->handle(
                $this->createRequest('test', 'get'),
                fn () => new Response,
                'test',
                'another',
            )->getStatusCode(),
        );
    }

    public function testMiddlewareStringCanBeReturned(): void
    {
        $this->assertEquals(
            'Hypervel\Pennant\Middleware\EnsureFeaturesAreActive:test,another',
            EnsureFeaturesAreActive::using('test', 'another'),
        );
    }

    /**
     * Create a request for the given URI and method.
     */
    protected function createRequest(string $uri, string $method): Request
    {
        $request = SymfonyRequest::create(
            uri: $uri,
            method: $method,
        );

        return Request::createFromBase(
            request: $request,
        );
    }
}
