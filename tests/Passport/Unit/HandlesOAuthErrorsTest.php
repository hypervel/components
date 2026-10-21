<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Closure;
use Hypervel\Http\Response;
use Hypervel\Passport\Exceptions\OAuthServerException;
use Hypervel\Passport\Http\Controllers\HandlesOAuthErrors;
use Hypervel\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class HandlesOAuthErrorsTest extends TestCase
{
    public function testShouldReturnCallbackResultIfNoErrorIsThrown(): void
    {
        $controller = new HandlesOAuthErrorsStubController;
        $response = new Response;

        $result = $controller->test(function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
    }

    public function testShouldHandleOAuthServerException(): void
    {
        $controller = new HandlesOAuthErrorsStubController;

        app()->instance(ResponseInterface::class, (new PsrHttpFactory)->createResponse(new Response));

        $e = null;

        try {
            $controller->test(function () {
                throw new LeagueException('Error', 1, 'fatal');
            });
        } catch (OAuthServerException $exception) {
            $e = $exception;
        }

        $this->assertInstanceOf(OAuthServerException::class, $e);
        $this->assertSame('Error', $e->getMessage());
        $this->assertSame(1, $e->getCode());
        $this->assertInstanceOf(LeagueException::class, $e->getPrevious());

        $response = $e->getResponse();

        $this->assertJsonStringEqualsJsonString(
            '{"error":"fatal","error_description":"Error"}',
            $response->getContent()
        );
    }

    public function testShouldIgnoreOtherExceptions(): void
    {
        $controller = new HandlesOAuthErrorsStubController;

        $exception = new RuntimeException('Exception occurred', 1);

        $this->expectException(RuntimeException::class);

        $controller->test(function () use ($exception) {
            throw $exception;
        });
    }
}

class HandlesOAuthErrorsStubController
{
    use HandlesOAuthErrors;

    /**
     * Execute the callback through the controller's error handling.
     */
    public function test(Closure $callback): mixed
    {
        return $this->withErrorHandling($callback);
    }
}
