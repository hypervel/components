<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use ArgumentCountError;
use Hypervel\Container\Container;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\TestCase;

class RequestTest extends TestCase
{
    public function testContainerResolutionAlwaysReturnsAFreshRequest(): void
    {
        $container = new Container;

        $first = $container->make(ContainerRequestStub::class);
        $second = $container->make(ContainerRequestStub::class);

        $this->assertNotSame($first, $second);
    }

    public function testMakeForwardsRequiredConstructorArguments(): void
    {
        $request = RequiredArgumentRequestStub::make('users/1');

        $this->assertSame('users/1', $request->resolveEndpoint());
    }

    public function testContainerResolutionOfRequiredArgumentRequestFailsNaturally(): void
    {
        $container = new Container;

        $this->expectException(ArgumentCountError::class);

        $container->make(RequiredArgumentRequestStub::class);
    }

    public function testCloneOwnsIndependentInitializedRequestState(): void
    {
        $request = (new ContainerRequestStub)
            ->withHeader('X-Original', 'yes')
            ->withQueryParameters(['page' => 1])
            ->withOptions(['verify' => true])
            ->delay(10)
            ->withCookies(['original' => 'yes'], '.example.test')
            ->retry([10])
            ->withData(['original' => true])
            ->disableCaching();
        $request->middleware()->onRequest(static fn ($pendingRequest) => $pendingRequest, 'original');

        $clone = clone $request;
        $clone
            ->withHeader('X-Clone', 'yes')
            ->withQueryParameters(['page' => 2])
            ->withOptions(['verify' => false])
            ->delay(20)
            ->withCookies(['clone' => 'yes'], 'api.example.test')
            ->retry(3, 20)
            ->withData(['clone' => true])
            ->enableCaching()
            ->invalidateCache();
        $clone->middleware()->onRequest(static fn ($pendingRequest) => $pendingRequest, 'clone');

        $this->assertSame('yes', $request->headers()['X-Original']);
        $this->assertSame('application/json', $request->headers()['Content-Type']);
        $this->assertSame(['page' => 1], $request->queryParameters());
        $this->assertTrue($request->options()['verify']);
        $this->assertSame(10, $request->delayMilliseconds());
        $this->assertCount(1, $request->middleware()->requestPipeline()->pipes());
        $this->assertSame(['original' => true], $request->body());
        $this->assertSame([
            ['cookies' => ['original' => 'yes'], 'domain' => '.example.test'],
        ], $request->cookies());
        $this->assertSame([10], $request->retryPolicy()->times);
        $this->assertFalse($request->cachingEnabled());
        $this->assertFalse($request->shouldInvalidateCache());

        $this->assertSame(['original' => true, 'clone' => true], $clone->body());
        $this->assertCount(2, $clone->cookies());
        $this->assertSame(3, $clone->retryPolicy()->times);
        $this->assertTrue($clone->cachingEnabled());
        $this->assertTrue($clone->shouldInvalidateCache());
    }
}

class ContainerRequestStub extends Request
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'users';
    }
}

class RequiredArgumentRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $endpoint)
    {
    }

    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }
}
