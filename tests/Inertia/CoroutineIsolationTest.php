<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Context\CoroutineContext;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\ResponseFactory;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testSharedPropsAreIsolatedBetweenCoroutines()
    {
        $results = parallel([
            function () {
                $factory = new ResponseFactory;
                $factory->share('user', 'Alice');
                usleep(1000);

                return $factory->getShared('user');
            },
            function () {
                $factory = new ResponseFactory;
                $factory->share('user', 'Bob');
                usleep(1000);

                return $factory->getShared('user');
            },
        ]);

        $this->assertContains('Alice', $results);
        $this->assertContains('Bob', $results);
        $this->assertCount(2, $results);
    }

    public function testRootViewIsIsolatedBetweenCoroutines()
    {
        $results = parallel([
            function () {
                $factory = new ResponseFactory;
                $factory->setRootView('layout-a');
                usleep(1000);

                return CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState)->rootView;
            },
            function () {
                $factory = new ResponseFactory;
                $factory->setRootView('layout-b');
                usleep(1000);

                return CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState)->rootView;
            },
        ]);

        $this->assertContains('layout-a', $results);
        $this->assertContains('layout-b', $results);
    }

    public function testInertiaStateIsDestroyedWhenCoroutineEnds()
    {
        // First parallel block: coroutine sets state then ends
        parallel([
            function () {
                $factory = new ResponseFactory;
                $factory->share('key', 'value');
            },
        ]);

        // Second parallel block: new coroutine should not see the state
        $results = parallel([
            function () {
                return CoroutineContext::get(InertiaState::CONTEXT_KEY);
            },
        ]);

        $this->assertNull($results[0]);
    }
}
