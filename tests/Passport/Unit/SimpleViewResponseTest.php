<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceUserCodeViewResponse;
use Hypervel\Passport\Http\Responses\SimpleViewResponse;
use Hypervel\Passport\Passport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class SimpleViewResponseTest extends TestCase
{
    #[TestWith(['viewNamespace', 'passport', 'passport::'])]
    #[TestWith(['viewPrefix', 'auth', 'auth.'])]
    #[TestWith(['viewPrefix', 'auth.', 'auth.'])]
    public function testConventionalViewsPreserveNamespacesAndDirectoryPrefixes(string $method, string $prefix, string $expectedPrefix): void
    {
        Passport::{$method}($prefix);
        $factory = m::mock(ResponseFactory::class);
        $this->app->instance(ResponseFactory::class, $factory);

        foreach ([
            AuthorizationViewResponse::class => 'authorize',
            DeviceAuthorizationViewResponse::class => 'device.authorize',
            DeviceUserCodeViewResponse::class => 'device.user-code',
        ] as $contract => $name) {
            $expected = new Response('Consent');
            $factory->shouldReceive('view')->once()->with($expectedPrefix . $name, [])->andReturn($expected);

            $this->assertSame($expected, $this->app->make($contract)->toResponse(Request::create('/')));
        }
    }

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
