<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\ServerFactory;
use Hypervel\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Mockery as m;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class ServerFactoryValidationTest extends TestCase
{
    public function testRepeatedValidationUsesOneResult(): void
    {
        $result = (new ServerRequest('GET', '/'))->withAttribute('oauth_access_token_id', 'token');
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->once()->andReturn($result);
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $factory = $this->factory();
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer token');

        $this->assertSame($result, $factory->validateAuthenticatedRequest($request));
        $this->assertSame($result, $factory->validateAuthenticatedRequest($request));
    }

    public function testFailedValidationIsAlsoReused(): void
    {
        $failure = OAuthServerException::accessDenied('Invalid signature');
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->once()->andThrow($failure);
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $factory = $this->factory();
        $request = Request::create('/');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $factory->validateAuthenticatedRequest($request);
                $this->fail('The invalid token was accepted.');
            } catch (OAuthServerException $exception) {
                $this->assertSame($failure, $exception);
            }
        }
    }

    public function testNewRequestsAndChangedCredentialsAreValidated(): void
    {
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->times(3)
            ->andReturnUsing(static fn (ServerRequestInterface $request): ServerRequestInterface => $request);
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $factory = $this->factory();
        $first = Request::create('/');
        $first->headers->set('Authorization', 'Bearer first');
        $second = Request::create('/');
        $second->headers->set('Authorization', 'Bearer first');

        $this->assertSame('Bearer first', $factory->validateAuthenticatedRequest($first)->getHeaderLine('Authorization'));
        $this->assertSame('Bearer first', $factory->validateAuthenticatedRequest($second)->getHeaderLine('Authorization'));
        $second->headers->set('Authorization', 'Bearer second');
        $this->assertSame('Bearer second', $factory->validateAuthenticatedRequest($second)->getHeaderLine('Authorization'));
    }

    public function testChangingTheResourceServerCannotReuseItsPredecessorsResult(): void
    {
        $firstResult = (new ServerRequest('GET', '/'))->withAttribute('oauth_client_id', 'first');
        $secondResult = (new ServerRequest('GET', '/'))->withAttribute('oauth_client_id', 'second');
        $first = m::mock(ResourceServer::class);
        $first->shouldReceive('validateAuthenticatedRequest')->once()->andReturn($firstResult);
        $second = m::mock(ResourceServer::class);
        $second->shouldReceive('validateAuthenticatedRequest')->once()->andReturn($secondResult);
        $factory = $this->factory();
        $request = Request::create('/');

        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $first);
        $this->assertSame('first', $factory->validateAuthenticatedRequest($request)->getAttribute('oauth_client_id'));
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $second);
        $this->assertSame('second', $factory->validateAuthenticatedRequest($request)->getAttribute('oauth_client_id'));
    }

    public function testExplicitValidationBypassesASuccessfulCachedResult(): void
    {
        $result = new ServerRequest('GET', '/');
        $failure = OAuthServerException::accessDenied('Revoked');
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->once()->ordered()->andReturn($result);
        $server->shouldReceive('validateAuthenticatedRequest')->once()->ordered()->andThrow($failure);
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $factory = $this->factory();
        $request = Request::create('/');

        $this->assertSame($result, $factory->validateAuthenticatedRequest($request));
        $this->expectExceptionObject($failure);

        $factory->validateAuthenticatedRequest($request, cache: false);
    }

    public function testExplicitValidationDoesNotPopulateTheCache(): void
    {
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->twice()->andReturn(new ServerRequest('GET', '/'));
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $factory = $this->factory();
        $request = Request::create('/');

        $factory->validateAuthenticatedRequest($request, cache: false);
        $factory->validateAuthenticatedRequest($request);
    }

    public function testBearerConversionDoesNotCopyTheRequestBody(): void
    {
        $server = m::mock(ResourceServer::class);
        $server->shouldReceive('validateAuthenticatedRequest')->once()
            ->andReturnUsing(function (ServerRequestInterface $request): ServerRequestInterface {
                $this->assertSame('', (string) $request->getBody());
                $this->assertNull($request->getParsedBody());
                $this->assertSame([], $request->getUploadedFiles());
                $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));

                return $request;
            });
        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $server);
        $request = Request::create('/api', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"data":"payload"}');
        $request->headers->set('Authorization', 'Bearer token');

        $this->factory()->validateAuthenticatedRequest($request);
    }

    /**
     * Create the real factory with an isolated service container.
     */
    protected function factory(): ServerFactory
    {
        return new ServerFactory(
            new Container,
            new Repository,
            m::mock(Encrypter::class),
            new BearerRequestFactory(new Psr17Factory),
        );
    }
}
