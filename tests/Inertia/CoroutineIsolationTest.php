<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Request;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\PropsResolver;
use Hypervel\Inertia\ResponseFactory;
use Hypervel\Inertia\ScrollMetadata;
use Hypervel\Inertia\ScrollProp;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    protected Closure $urlResolver;

    protected Closure $componentTransformer;

    protected Closure $ssrRequestConfigurator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->urlResolver = fn () => '/boot-url';
        $this->componentTransformer = fn (string $component) => "Boot/{$component}";
        $this->ssrRequestConfigurator = fn (PendingRequest $request): PendingRequest => $request;

        $factory = new ResponseFactory;
        $factory->share('boot', 'shared');
        $factory->setRootView('boot-layout');
        $factory->version('boot-version');
        $factory->encryptHistory();
        $factory->resolveUrlUsing($this->urlResolver);
        $factory->transformComponentUsing($this->componentTransformer);
        $factory->disableSsr();
        $factory->withoutSsr('/private');
        $factory->configureSsrRequestUsing($this->ssrRequestConfigurator);
    }

    public function testBootConfigurationIsInheritedByRequestCoroutines(): void
    {
        [$state] = parallel([
            fn () => InertiaState::current(),
        ]);

        $this->assertSame(['boot' => 'shared'], $state->sharedProps);
        $this->assertSame('boot-layout', $state->rootView);
        $this->assertSame('boot-version', $state->version);
        $this->assertTrue($state->encryptHistory);
        $this->assertSame($this->urlResolver, $state->urlResolver);
        $this->assertSame($this->componentTransformer, $state->componentTransformer);
        $this->assertTrue($state->ssrDisabled);
        $this->assertSame(['/private'], $state->ssrExcludedPaths);
        $this->assertSame($this->ssrRequestConfigurator, $state->ssrRequestConfigurator);
        $this->assertSame([], $state->page);
        $this->assertFalse($state->ssrDispatched);
        $this->assertNull($state->ssrResponse);
    }

    public function testSharedPropsAreIsolatedBetweenCoroutines(): void
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

    public function testRootViewIsIsolatedBetweenCoroutines(): void
    {
        $results = parallel([
            function () {
                $factory = new ResponseFactory;
                $factory->setRootView('layout-a');
                usleep(1000);

                return InertiaState::current()->rootView;
            },
            function () {
                $factory = new ResponseFactory;
                $factory->setRootView('layout-b');
                usleep(1000);

                return InertiaState::current()->rootView;
            },
        ]);

        $this->assertContains('layout-a', $results);
        $this->assertContains('layout-b', $results);
    }

    public function testSsrRequestConfiguratorIsIsolatedBetweenCoroutines(): void
    {
        $first = fn (PendingRequest $request): PendingRequest => $request->withHeader('X-Tenant', 'first');
        $second = fn (PendingRequest $request): PendingRequest => $request->withHeader('X-Tenant', 'second');

        $results = parallel([
            function () use ($first): Closure {
                (new ResponseFactory)->configureSsrRequestUsing($first);
                usleep(1000);

                return InertiaState::current()->ssrRequestConfigurator;
            },
            function () use ($second): Closure {
                (new ResponseFactory)->configureSsrRequestUsing($second);
                usleep(1000);

                return InertiaState::current()->ssrRequestConfigurator;
            },
        ]);

        $this->assertSame($first, $results[0]);
        $this->assertSame($second, $results[1]);
    }

    public function testFlushSharedOnlyClearsTheCurrentRequest(): void
    {
        [$flushed, $sibling] = parallel([
            function () {
                $factory = new ResponseFactory;
                $factory->flushShared();
                usleep(1000);

                return $factory->getShared();
            },
            function () {
                usleep(1000);

                return (new ResponseFactory)->getShared();
            },
        ]);

        $this->assertSame([], $flushed);
        $this->assertSame(['boot' => 'shared'], $sibling);

        [$subsequent] = parallel([
            fn () => (new ResponseFactory)->getShared(),
        ]);

        $this->assertSame(['boot' => 'shared'], $subsequent);
    }

    public function testBootSharedScrollPropsResolveIndependentlyBetweenRequestCoroutines(): void
    {
        $scrollProp = new ScrollProp(
            fn () => ['data' => [['id' => request()->query('id')]]],
            'data',
            new ScrollMetadata('page', null, 2, 1),
        );

        (new ResponseFactory)->share('feed', $scrollProp);
        CoroutineContext::copyToNonCoroutine([InertiaState::CONTEXT_KEY]);

        $resolve = fn (string $id): Closure => function () use ($id): array {
            $request = Request::create("/?id={$id}");
            RequestContext::set($request);

            [$props, $metadata] = (new PropsResolver($request, 'TestComponent'))
                ->resolve(InertiaState::current()->sharedProps, []);

            return [$props['feed']['data'][0]['id'], $metadata];
        };

        [$first, $second] = parallel([
            $resolve('first'),
            $resolve('second'),
        ]);

        $this->assertSame('first', $first[0]);
        $this->assertSame('second', $second[0]);

        foreach ([$first[1], $second[1]] as $metadata) {
            $this->assertSame(['feed.data'], $metadata['mergeProps']);
            $this->assertSame([
                'feed' => [
                    'pageName' => 'page',
                    'previousPage' => null,
                    'nextPage' => 2,
                    'currentPage' => 1,
                    'reset' => false,
                ],
            ], $metadata['scrollProps']);
        }

        $this->assertSame([], $scrollProp->appendsAtPaths());
    }

    public function testCopiedInertiaStateIsIndependent(): void
    {
        [$result] = parallel([
            function () {
                $parent = InertiaState::current();
                $parent->sharedProps['parent'] = true;

                [$child] = parallel([
                    function () {
                        $state = InertiaState::current();
                        $state->sharedProps['child'] = true;

                        return $state->sharedProps;
                    },
                ], copyContext: true);

                return [$parent->sharedProps, $child];
            },
        ]);

        [$parent, $child] = $result;

        $this->assertSame(['boot' => 'shared', 'parent' => true], $parent);
        $this->assertSame(['boot' => 'shared', 'parent' => true, 'child' => true], $child);
    }

    public function testInertiaStateIsDestroyedWhenCoroutineEnds(): void
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
                return (new ResponseFactory)->getShared();
            },
        ]);

        $this->assertArrayNotHasKey('key', $results[0]);
    }
}
