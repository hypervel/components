<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class KernelTest extends TestCase
{
    public function testAddToMiddlewarePriorityAfterWithSingleMiddleware()
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

    public function testAddToMiddlewarePriorityAfterWithArrayOfMiddleware()
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

    public function testAddToMiddlewarePriorityAfterWhenExistingNotFound()
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

    public function testAddToMiddlewarePriorityAfterDoesNotAddDuplicates()
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

    public function testAddToMiddlewarePriorityBeforeWithSingleMiddleware()
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

    public function testAddToMiddlewarePriorityBeforeWithArrayOfMiddleware()
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

    public function testAddToMiddlewarePriorityBeforeWhenExistingNotFound()
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

    public function testAddToMiddlewarePriorityBeforeDoesNotAddDuplicates()
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

    public function testAddToMiddlewarePriorityBeforeAtBeginning()
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

    public function testAddToMiddlewarePriorityAfterAtEnd()
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

    public function testAddToMiddlewarePriorityReturnsSelf()
    {
        $kernel = $this->getKernel();
        $kernel->setMiddlewarePriority(['middleware_a']);

        $result = $kernel->addToMiddlewarePriorityAfter('middleware_a', 'new_middleware');
        $this->assertSame($kernel, $result);

        $result = $kernel->addToMiddlewarePriorityBefore('middleware_a', 'another_middleware');
        $this->assertSame($kernel, $result);
    }

    public function testItTriggersTerminatingEvent()
    {
        $called = [];
        $app = new Application();
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $kernel = new Kernel($app, new Router($events, $app));
        $app->instance('terminating-middleware', new class($called) {
            public function __construct(private &$called)
            {
            }

            public function handle($request, $next)
            {
                return $next($request);
            }

            public function terminate($request, $response): void
            {
                $this->called[] = 'terminating middleware';
            }
        });
        $kernel->setGlobalMiddleware([
            'terminating-middleware',
        ]);
        $events->listen(function (Terminating $terminating) use (&$called) {
            $called[] = 'terminating event';
        });
        $app->terminating(function () use (&$called) {
            $called[] = 'terminating callback';
        });

        $kernel->terminate(new Request(), new Response());

        $this->assertSame([
            'terminating event',
            'terminating middleware',
            'terminating callback',
        ], $called);
    }

    protected function getKernel(): Kernel
    {
        return new Kernel(
            new Application(),
            m::mock(Router::class),
        );
    }
}
