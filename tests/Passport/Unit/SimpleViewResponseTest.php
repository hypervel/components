<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\Http\Responses\SimpleViewResponse;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SimpleViewResponseTest extends TestCase
{
    public function testNamedViewsReceiveTheirParameters(): void
    {
        $expected = new Response('Consent');
        $factory = m::mock(ResponseFactory::class);
        $factory->shouldReceive('view')->once()->with('oauth.authorize', ['client' => 'example'])->andReturn($expected);
        $this->app->instance(ResponseFactory::class, $factory);

        $response = (new SimpleViewResponse('oauth.authorize'))->withParameters(['client' => 'example']);

        $this->assertSame($expected, $response->toResponse(Request::create('/oauth/authorize')));
    }

    public function testCallbacksCanReturnResponsableObjects(): void
    {
        $request = Request::create('/oauth/authorize');
        $expected = new Response('Consent', 202, ['X-Consent' => 'pending']);
        $responsable = m::mock(Responsable::class);
        $responsable->shouldReceive('toResponse')->once()->with($request)->andReturn($expected);
        $response = new SimpleViewResponse(function (array $parameters) use ($responsable): Responsable {
            $this->assertSame(['client' => 'example'], $parameters);

            return $responsable;
        });

        $this->assertSame($expected, $response->withParameters(['client' => 'example'])->toResponse($request));
    }
}
