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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;

class SimpleViewResponseTest extends TestCase
{
    use VerifiesDoubles;

    #[TestWith(['viewNamespace', 'passport', 'passport::'])]
    #[TestWith(['viewPrefix', 'auth', 'auth.'])]
    #[TestWith(['viewPrefix', 'auth.', 'auth.'])]
    public function testConventionalViewsPreserveNamespacesAndDirectoryPrefixes(string $method, string $prefix, string $expectedPrefix): void
    {
        Passport::{$method}($prefix);
        $factory = Double::for(ResponseFactory::class)->strict();
        $this->app->instance(ResponseFactory::class, $factory);

        foreach ([
            AuthorizationViewResponse::class => 'authorize',
            DeviceAuthorizationViewResponse::class => 'device.authorize',
            DeviceUserCodeViewResponse::class => 'device.user-code',
        ] as $contract => $name) {
            $expected = new Response('Consent');
            $factory->expects('view')->with($expectedPrefix . $name, [])->returns($expected);

            $this->assertSame($expected, $this->app->make($contract)->toResponse(Request::create('/')));
        }
    }

    public function testNamedViewsReceiveTheirParameters(): void
    {
        $expected = new Response('Consent');
        $factory = Double::for(ResponseFactory::class)->strict();
        $factory->expects('view')->with('oauth.authorize', ['client' => 'example'])->returns($expected);
        $this->app->instance(ResponseFactory::class, $factory);

        $response = (new SimpleViewResponse('oauth.authorize'))->withParameters(['client' => 'example']);

        $this->assertSame($expected, $response->toResponse(Request::create('/oauth/authorize')));
    }

    public function testCallbacksCanReturnResponsableObjects(): void
    {
        $request = Request::create('/oauth/authorize');
        $expected = new Response('Consent', 202, ['X-Consent' => 'pending']);
        $responsable = Double::for(Responsable::class)->strict();
        $responsable->expects('toResponse')->with(Argument::same($request))->returns($expected);
        $response = new SimpleViewResponse(function (array $parameters) use ($responsable): Responsable {
            $this->assertSame(['client' => 'example'], $parameters);

            return $responsable;
        });

        $this->assertSame($expected, $response->withParameters(['client' => 'example'])->toResponse($request));
    }
}
