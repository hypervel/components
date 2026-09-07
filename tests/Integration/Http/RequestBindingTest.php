<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;

class RequestBindingTest extends TestCase
{
    public function testFallbackRequestUsesTheConfiguredApplicationUrl(): void
    {
        config(['app.url' => 'https://example.test/base']);
        RequestContext::forget();

        $request = $this->app->make('request');

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('https://example.test/base', $request->getUri());
    }

    public function testFallbackRequestIsFreshForEveryResolution(): void
    {
        RequestContext::forget();

        $firstRequest = $this->app->make('request');
        $firstRequest->merge(['name' => 'John']);
        $secondRequest = $this->app->make('request');

        $this->assertNotSame($firstRequest, $secondRequest);
        $this->assertSame('John', $firstRequest->input('name'));
        $this->assertNull($secondRequest->input('name'));
    }

    public function testFallbackRequestUsesLocalhostWhenTheApplicationUrlIsMissing(): void
    {
        $app = config()->array('app');
        unset($app['url']);
        config(['app' => $app]);
        RequestContext::forget();

        $request = $this->app->make('request');

        $this->assertSame('http://localhost/', $request->getUri());
    }

    public function testContextualRequestIsReturnedForEveryResolution(): void
    {
        $request = RequestContext::set(Request::create('/?name=John'));

        $this->assertSame($request, $this->app->make('request'));
        $this->assertSame($request, $this->app->make('request'));
        $this->assertSame('John', request('name'));
    }
}
