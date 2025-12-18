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

    public function testAddToMiddlewarePriorityAfterWithSingleMiddleware(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        $kernel->addToMiddlewarePriorityAfter('middleware_b', 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'new_middleware',
            'middleware_c',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityAfterWithArrayOfMiddleware(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        // When array is given, it inserts after the LAST found middleware in the array
        $kernel->addToMiddlewarePriorityAfter(['middleware_a', 'middleware_c'], 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'middleware_c',
            'new_middleware',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityAfterWhenExistingNotFound(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
        ]);

        // When target middleware not found, should append to end
        $kernel->addToMiddlewarePriorityAfter('non_existent', 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'new_middleware',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityAfterDoesNotAddDuplicates(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        $kernel->addToMiddlewarePriorityAfter('middleware_a', 'middleware_b');

        // middleware_b already exists, should not be added again
        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityBeforeWithSingleMiddleware(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        $kernel->addToMiddlewarePriorityBefore('middleware_b', 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'new_middleware',
            'middleware_b',
            'middleware_c',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityBeforeWithArrayOfMiddleware(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        // When array is given, it inserts before the FIRST found middleware in the array
        $kernel->addToMiddlewarePriorityBefore(['middleware_b', 'middleware_c'], 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'new_middleware',
            'middleware_b',
            'middleware_c',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityBeforeWhenExistingNotFound(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
        ]);

        // When target middleware not found, appends to end (same as After behavior)
        // This matches Laravel's behavior - if target doesn't exist, append is the safe fallback
        $kernel->addToMiddlewarePriorityBefore('non_existent', 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'new_middleware',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityBeforeDoesNotAddDuplicates(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ]);

        $kernel->addToMiddlewarePriorityBefore('middleware_c', 'middleware_a');

        // middleware_a already exists, should not be added again
        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'middleware_c',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityBeforeAtBeginning(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
        ]);

        $kernel->addToMiddlewarePriorityBefore('middleware_a', 'new_middleware');

        $this->assertSame([
            'new_middleware',
            'middleware_a',
            'middleware_b',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityAfterAtEnd(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority([
            'middleware_a',
            'middleware_b',
        ]);

        $kernel->addToMiddlewarePriorityAfter('middleware_b', 'new_middleware');

        $this->assertSame([
            'middleware_a',
            'middleware_b',
            'new_middleware',
        ], $kernel->getMiddlewarePriority());
    }

    public function testAddToMiddlewarePriorityReturnsSelf(): void
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority(['middleware_a']);

        $result = $kernel->addToMiddlewarePriorityAfter('middleware_a', 'new_middleware');
        $this->assertSame($kernel, $result);

        $result = $kernel->addToMiddlewarePriorityBefore('middleware_a', 'another_middleware');
        $this->assertSame($kernel, $result);
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
