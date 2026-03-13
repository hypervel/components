<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\PrecognitionDispatcherCachingTest;

use Hypervel\Container\Container;
use Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Hypervel\Foundation\Routing\PrecognitionCallableDispatcher;
use Hypervel\Foundation\Routing\PrecognitionControllerDispatcher;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Controller;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class PrecognitionDispatcherCachingTest extends TestCase
{
    public function testCallableDispatcherPrecognitionPropertyStartsNull()
    {
        $dispatcher = new CallableDispatcher(new Container());
        $ref = new ReflectionProperty($dispatcher, 'precognitionDispatcher');

        $this->assertNull($ref->getValue($dispatcher));
    }

    public function testControllerDispatcherPrecognitionPropertyStartsNull()
    {
        $dispatcher = new ControllerDispatcher(new Container());
        $ref = new ReflectionProperty($dispatcher, 'precognitionDispatcher');

        $this->assertNull($ref->getValue($dispatcher));
    }

    public function testCallableDispatcherCachesPrecognitionInstanceAcrossDispatches()
    {
        $route = Route::get('foo', function () {
            return 'ok';
        })->middleware(HandlePrecognitiveRequests::class);

        $this->get('foo', ['Precognition' => 'true'])->assertNoContent();

        // Get the dispatcher cached on the Route — this is the instance
        // that actually handled the dispatch, not a fresh container resolve.
        $routeRef = new ReflectionProperty($route, 'callableDispatcher');
        $dispatcher = $routeRef->getValue($route);

        $this->assertNotNull($dispatcher);

        $ref = new ReflectionProperty($dispatcher, 'precognitionDispatcher');
        $first = $ref->getValue($dispatcher);

        $this->assertInstanceOf(PrecognitionCallableDispatcher::class, $first);

        // Second dispatch reuses the same cached instance
        $this->get('foo', ['Precognition' => 'true'])->assertNoContent();

        $second = $ref->getValue($dispatcher);
        $this->assertSame($first, $second);
    }

    public function testControllerDispatcherCachesPrecognitionInstanceAcrossDispatches()
    {
        $route = Route::get('foo', [PrecognitionCachingController::class, 'index'])
            ->middleware(HandlePrecognitiveRequests::class);

        $this->get('foo', ['Precognition' => 'true'])->assertNoContent();

        // Get the dispatcher cached on the Route — this is the instance
        // that actually handled the dispatch, not a fresh container resolve.
        $routeRef = new ReflectionProperty($route, 'controllerDispatcher');
        $dispatcher = $routeRef->getValue($route);

        $this->assertNotNull($dispatcher);

        $ref = new ReflectionProperty($dispatcher, 'precognitionDispatcher');
        $first = $ref->getValue($dispatcher);

        $this->assertInstanceOf(PrecognitionControllerDispatcher::class, $first);

        // Second dispatch reuses the same cached instance
        $this->get('foo', ['Precognition' => 'true'])->assertNoContent();

        $second = $ref->getValue($dispatcher);
        $this->assertSame($first, $second);
    }

    public function testNewDispatcherInstancesHaveIndependentCaches()
    {
        $container = new Container();

        $dispatcher1 = new CallableDispatcher($container);
        $dispatcher2 = new CallableDispatcher($container);

        $ref1 = new ReflectionProperty($dispatcher1, 'precognitionDispatcher');
        $ref2 = new ReflectionProperty($dispatcher2, 'precognitionDispatcher');

        $ref1->setValue($dispatcher1, new PrecognitionCallableDispatcher($container));

        $this->assertNotNull($ref1->getValue($dispatcher1));
        $this->assertNull($ref2->getValue($dispatcher2));
    }
}

class PrecognitionCachingController extends Controller
{
    public function index(): string
    {
        return 'ok';
    }
}
