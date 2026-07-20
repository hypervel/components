<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Carbon\Carbon;
use Hypervel\Config\Repository;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

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
        $app = new Application;
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

        $kernel->terminate(new Request, new Response);

        $this->assertSame([
            'terminating event',
            'terminating middleware',
            'terminating callback',
        ], $called);
    }

    public function testHandleRethrowsAfterAResponseIsCommitted(): void
    {
        $app = new Application;
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $app->bootstrapWith([]);
        $failure = new RuntimeException('stream failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $handler->shouldReceive('render')->andReturn(new Response('replacement', 500));
        $app->instance(ExceptionHandler::class, $handler);
        $router = m::mock(Router::class);
        $router->shouldReceive('dispatch')->once()->andThrow($failure);
        $kernel = new Kernel($app, $router);
        $committedResponse = new Response;
        $committedResponse->markStreamed();
        ResponseContext::set($committedResponse);

        try {
            $kernel->handle(Request::create('/'));
            $this->fail('Expected the committed stream failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            ResponseContext::forget();
        }
    }

    public function testTerminationIsExhaustiveAndPreservesTheFirstFailure(): void
    {
        $called = [];
        $app = new Application;
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->bootstrapWith([]);
        $router = m::mock(Router::class);
        $router->shouldReceive('dispatch')->once()->andReturn(new Response);
        $kernel = new Kernel($app, $router);
        $request = Request::create('/');
        $response = $kernel->handle($request);
        $eventFailure = new RuntimeException('event failed');
        $middlewareFailure = new RuntimeException('middleware failed');
        $applicationFailure = new RuntimeException('application failed');
        $durationFailure = new RuntimeException('duration failed');

        $events->listen(function (Terminating $event) use (&$called, $eventFailure): void {
            $called[] = 'event';

            throw $eventFailure;
        });
        $app->instance('first-middleware', new class($called, $middlewareFailure) {
            public function __construct(private &$called, private RuntimeException $failure)
            {
            }

            public function terminate(Request $request, Response $response): void
            {
                $this->called[] = 'first middleware';

                throw $this->failure;
            }
        });
        $app->instance('second-middleware', new class($called) {
            public function __construct(private &$called)
            {
            }

            public function terminate(Request $request, Response $response): void
            {
                $this->called[] = 'second middleware';
            }
        });
        $kernel->setGlobalMiddleware(['first-middleware', 'second-middleware']);
        $app->terminating(function () use (&$called, $applicationFailure): void {
            $called[] = 'first application';

            throw $applicationFailure;
        });
        $app->terminating(function () use (&$called): void {
            $called[] = 'second application';
        });
        $kernel->whenRequestLifecycleIsLongerThan(-1, function () use (&$called, $durationFailure): void {
            $called[] = 'first duration';

            throw $durationFailure;
        });
        $kernel->whenRequestLifecycleIsLongerThan(-1, function () use (&$called): void {
            $called[] = 'second duration';
        });

        try {
            $kernel->terminate($request, $response);
            $this->fail('Expected the first termination failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($eventFailure, $exception);
        }

        $this->assertSame([
            'event',
            'first middleware',
            'second middleware',
            'first application',
            'second application',
            'first duration',
            'second duration',
        ], $called);
        $this->assertNull($kernel->requestStartedAt());
    }

    public function testRequestStartedAtIsIsolatedBetweenConcurrentCoroutines()
    {
        $app = new Application;
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->bootstrapWith([]);

        $router = m::mock(Router::class);
        $router->shouldReceive('dispatch')->andReturn(new Response);

        $kernel = new Kernel($app, $router);

        $captured = [];
        $kernel->whenRequestLifecycleIsLongerThan(0, function (Carbon $startedAt, Request $request) use (&$captured) {
            $captured[$request->headers->get('X-Coroutine')] = $startedAt;
        });

        parallel([
            function () use ($kernel) {
                $request = Request::create('/');
                $request->headers->set('X-Coroutine', 'A');
                $response = $kernel->handle($request);
                usleep(20000);
                $kernel->terminate($request, $response);
            },
            function () use ($kernel) {
                usleep(10000);
                $request = Request::create('/');
                $request->headers->set('X-Coroutine', 'B');
                $response = $kernel->handle($request);
                usleep(20000);
                $kernel->terminate($request, $response);
            },
        ]);

        $this->assertArrayHasKey('A', $captured, 'Coroutine A duration handler did not fire — its requestStartedAt was wiped by coroutine B.');
        $this->assertArrayHasKey('B', $captured, 'Coroutine B duration handler did not fire — its requestStartedAt was wiped by coroutine A.');

        $this->assertTrue(
            $captured['A']->lt($captured['B']),
            sprintf(
                'requestStartedAt leaked between coroutines: A captured %s, B captured %s (expected A < B because A started first).',
                $captured['A']->toIso8601String(),
                $captured['B']->toIso8601String()
            )
        );
    }

    protected function getKernel(): Kernel
    {
        return new Kernel(
            new Application,
            m::mock(Router::class),
        );
    }
}
