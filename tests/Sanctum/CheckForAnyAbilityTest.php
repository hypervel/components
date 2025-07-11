<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class CheckForAnyAbilityTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    /**
     * Test request is passed along if any abilities are present on token
     */
    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken(): void
    {
        $middleware = new CheckForAnyAbility('foo', 'bar');
        
        // Create a user object with the required methods
        $user = new class {
            private $token;
            
            public function __construct() {
                $this->token = new class {};
            }
            
            public function currentAccessToken() {
                return $this->token;
            }
            
            public function tokenCan(string $ability): bool {
                // Return true only for 'foo', false for others
                return $ability === 'foo';
            }
        };
        
        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->with('user')->andReturn($user);

        $response = Mockery::mock(ResponseInterface::class);
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->with($request)->andReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility(): void
    {
        $this->expectException(\Hypervel\Sanctum\Exceptions\MissingAbilityException::class);

        $middleware = new CheckForAnyAbility('foo', 'bar');
        
        $user = new class {
            private $token;
            
            public function __construct() {
                $this->token = new class {};
            }
            
            public function currentAccessToken() {
                return $this->token;
            }
            
            public function tokenCan(string $ability): bool {
                return false;
            }
        };
        
        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->with('user')->andReturn($user);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $middleware->process($request, $handler);
    }

    public function testExceptionIsThrownIfNoAuthenticatedUser(): void
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $middleware = new CheckForAnyAbility('foo', 'bar');
        
        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->with('user')->once()->andReturn(null);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $middleware->process($request, $handler);
    }

    public function testExceptionIsThrownIfNoToken(): void
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $middleware = new CheckForAnyAbility('foo', 'bar');
        
        $user = new class {
            public function currentAccessToken() {
                return null;
            }
            
            public function tokenCan(string $ability): bool {
                return false;
            }
        };
        
        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->with('user')->andReturn($user);

        $handler = Mockery::mock(RequestHandlerInterface::class);

        $middleware->process($request, $handler);
    }
}