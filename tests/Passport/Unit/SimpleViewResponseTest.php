<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\Http\Responses\SimpleViewResponse;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Nyholm\Psr7\Response as PsrResponse;

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

    public function testCallbackParametersCanBeReturnedAsJson(): void
    {
        $response = (new SimpleViewResponse(fn (array $parameters): array => $parameters))
            ->withParameters(['client' => 'example'])->toResponse(Request::create('/oauth/authorize'));

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(['client' => 'example'], json_decode($response->getContent(), true));
    }

    public function testCallbacksCanReturnPsrResponsesWithTheirStatusAndHeaders(): void
    {
        $response = (new SimpleViewResponse(fn (): PsrResponse => new PsrResponse(202, ['X-Consent' => 'pending'], 'Review')))
            ->toResponse(Request::create('/oauth/authorize'));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending', $response->headers->get('X-Consent'));
        $this->assertSame('Review', $response->getContent());
    }
}
