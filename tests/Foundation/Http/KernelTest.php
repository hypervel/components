<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hyperf\Dispatcher\HttpDispatcher;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpServer\ResponseEmitter;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Dispatcher\ParsedMiddleware;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class KernelTest extends TestCase
{
    use HasMockedApplication;

    public function testMiddleware()
    {
        $kernel = $this->getKernel();
        $kernel->setGlobalMiddleware([
            'top_middleware',
            'b_middleware:foo',
            'a_middleware',
            'alias2',
            'c_middleware',
            'alias1',
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
            'alias1_middleware',
            'alias2_middleware',
            'group1_middleware1:bar',
            'group1_middleware2',
        ], array_map(fn (ParsedMiddleware $middleware) => $middleware->getSignature(), $result));
    }

    protected function getKernel(): Kernel
    {
        return new Kernel(
            $this->getApplication(),
            m::mock(HttpDispatcher::class),
            m::mock(ExceptionHandlerDispatcher::class),
            m::mock(ResponseEmitter::class)
        );
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
}
