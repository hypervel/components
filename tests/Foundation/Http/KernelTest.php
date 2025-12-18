<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hyperf\Dispatcher\HttpDispatcher;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\ResponseEmitter;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use Hypervel\Dispatcher\ParsedMiddleware;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Router\Exceptions\InvalidMiddlewareExclusionException;
use Hypervel\Router\MiddlewareExclusionManager;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class KernelTest extends TestCase
{
    use HasMockedApplication;

    protected function tearDown(): void
    {
        parent::tearDown();

        MiddlewareManager::$container = [];
        MiddlewareExclusionManager::clear();
    }

    public function testMiddleware()
    {
        $kernel = $this->getKernel();
        $kernel->setGlobalMiddleware([
            'top_middleware',
            'b_middleware:foo',
            'a_middleware',
            'alias2',
            'c_middleware',
            'alias1:foo,bar',
            'group1',
        ]);
        $kernel->setMiddlewareGroups([
            'group1' => [
                'group1_middleware2',
                'group1_middleware1:bar',
            ],
        ]);
        $kernel->setMiddlewareAliases([
            'alias1' => 'alias1_middleware',
            'alias2' => 'alias2_middleware',
        ]);
        $kernel->setMiddlewarePriority([
            'a_middleware',
            'b_middleware',
            'c_middleware',
            'alias1_middleware',
            'alias2_middleware',
            'group1_middleware1',
            'group1_middleware2',
        ]);

        $result = $kernel->getMiddlewareForRequest($this->getRequest());

        $this->assertSame([
            'top_middleware',
            'a_middleware',
            'b_middleware:foo',
            'c_middleware',
            'alias1_middleware:foo,bar',
            'alias2_middleware',
            'group1_middleware1:bar',
            'group1_middleware2',
        ], array_map(fn (ParsedMiddleware $middleware) => $middleware->getSignature(), $result));
    }

    public function testMiddlewareExclusionFromGroup()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareGroups([
            'web' => [
                'session_middleware',
                'csrf_middleware',
                'share_errors_middleware',
            ],
        ]);

        // Register route middleware
        MiddlewareManager::addMiddlewares('http', '/test', 'POST', ['web']);

        // Register exclusion - csrf_middleware should be excluded from the 'web' group
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['csrf_middleware']);

        $result = $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'POST'));

        $signatures = array_map(fn (ParsedMiddleware $m) => $m->getSignature(), $result);

        // csrf_middleware should be excluded, others should remain
        $this->assertContains('session_middleware', $signatures);
        $this->assertContains('share_errors_middleware', $signatures);
        $this->assertNotContains('csrf_middleware', $signatures);
    }

    public function testMiddlewareExclusionWithAlias()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareAliases([
            'csrf' => 'App\Http\Middleware\VerifyCsrfToken',
        ]);
        $kernel->setMiddlewareGroups([
            'web' => [
                'session_middleware',
                'csrf', // Using alias
            ],
        ]);

        // Register route middleware
        MiddlewareManager::addMiddlewares('http', '/test', 'POST', ['web']);

        // Exclude using the full class name
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['App\Http\Middleware\VerifyCsrfToken']);

        $result = $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'POST'));

        $signatures = array_map(fn (ParsedMiddleware $m) => $m->getSignature(), $result);

        $this->assertContains('session_middleware', $signatures);
        $this->assertNotContains('App\Http\Middleware\VerifyCsrfToken', $signatures);
    }

    public function testMiddlewareExclusionResolvesAliasInExclusion()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareAliases([
            'csrf' => 'App\Http\Middleware\VerifyCsrfToken',
        ]);
        $kernel->setMiddlewareGroups([
            'web' => [
                'session_middleware',
                'App\Http\Middleware\VerifyCsrfToken', // Using full class name
            ],
        ]);

        // Register route middleware
        MiddlewareManager::addMiddlewares('http', '/test', 'POST', ['web']);

        // Exclude using alias - should resolve to class name
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['csrf']);

        $result = $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'POST'));

        $signatures = array_map(fn (ParsedMiddleware $m) => $m->getSignature(), $result);

        $this->assertContains('session_middleware', $signatures);
        $this->assertNotContains('App\Http\Middleware\VerifyCsrfToken', $signatures);
    }

    public function testMiddlewareExclusionExpandsGroupInExclusion()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareGroups([
            'web' => [
                'session_middleware',
                'csrf_middleware',
            ],
            'api' => [
                'throttle_middleware',
                'api_middleware',
            ],
        ]);

        // Route uses both groups
        MiddlewareManager::addMiddlewares('http', '/test', 'POST', ['web', 'api']);

        // Exclude the entire 'api' group
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['api']);

        $result = $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'POST'));

        $signatures = array_map(fn (ParsedMiddleware $m) => $m->getSignature(), $result);

        // web group should remain
        $this->assertContains('session_middleware', $signatures);
        $this->assertContains('csrf_middleware', $signatures);

        // api group should be excluded
        $this->assertNotContains('throttle_middleware', $signatures);
        $this->assertNotContains('api_middleware', $signatures);
    }

    public function testMiddlewareExclusionWithNoExclusions()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareGroups([
            'web' => [
                'session_middleware',
                'csrf_middleware',
            ],
        ]);

        // Register route middleware without exclusions
        MiddlewareManager::addMiddlewares('http', '/test', 'GET', ['web']);

        $result = $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'GET'));

        $signatures = array_map(fn (ParsedMiddleware $m) => $m->getSignature(), $result);

        // All middleware should be present
        $this->assertContains('session_middleware', $signatures);
        $this->assertContains('csrf_middleware', $signatures);
    }

    public function testMiddlewareExclusionThrowsExceptionForParameterizedExclusion()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewareAliases([
            'throttle' => 'App\Http\Middleware\Throttle',
        ]);
        $kernel->setMiddlewareGroups([
            'api' => ['throttle:60,1', 'api_middleware'],
        ]);

        MiddlewareManager::addMiddlewares('http', '/test', 'POST', ['api']);

        // Exclusion with parameters should throw - parameters don't belong in exclusions
        MiddlewareExclusionManager::addExcluded('http', '/test', 'POST', ['throttle:60,1']);

        $this->expectException(InvalidMiddlewareExclusionException::class);
        $this->expectExceptionMessage("Middleware exclusion 'throttle:60,1' should not contain parameters. Use 'throttle' instead.");

        $kernel->getMiddlewareForRequest($this->getFoundRequest('/test', 'POST'));
    }

    protected function getKernel(string $serverName = 'http'): Kernel
    {
        $kernel = new Kernel(
            $this->getApplication(),
            m::mock(HttpDispatcher::class),
            m::mock(ExceptionHandlerDispatcher::class),
            m::mock(ResponseEmitter::class)
        );

        // Initialize the server name (normally done by initCoreMiddleware)
        $reflection = new ReflectionProperty($kernel, 'serverName');
        $reflection->setValue($kernel, $serverName);

        return $kernel;
    }

    protected function getRequest(): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->once()
            ->andReturnSelf();
        $request->shouldReceive('isFound')
            ->once()
            ->andReturn(false);

        return $request;
    }

    protected function getFoundRequest(string $route, string $method): Request
    {
        $handler = new Handler('TestHandler', $route);

        $dispatched = m::mock(Dispatched::class);
        $dispatched->handler = $handler;
        $dispatched->shouldReceive('isFound')->andReturn(true);

        $request = m::mock(Request::class);
        $request->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn($dispatched);
        $request->shouldReceive('getMethod')
            ->andReturn($method);

        return $request;
    }
}
