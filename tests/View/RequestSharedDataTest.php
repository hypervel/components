<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory;
use Hypervel\View\Middleware\ShareErrorsFromSession;
use Hypervel\View\RequestSharedData;
use Hypervel\View\ViewFinderInterface;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Coroutine\parallel;

class RequestSharedDataTest extends TestCase
{
    public function testRequestDataMergesBetweenGlobalAndLocalData(): void
    {
        $factory = $this->factory();
        $factory->share(['global' => 'global', 'overridden' => 'global']);

        RequestSharedData::scope([
            'request' => 'request',
            'overridden' => 'request',
        ], function () use ($factory): void {
            $this->assertSame([
                '__env' => $factory,
                'global' => 'global',
                'overridden' => 'local',
                'request' => 'request',
                'local' => 'local',
            ], $factory->mergeSharedData([
                'local' => 'local',
                'overridden' => 'local',
            ]));

            $this->assertSame('request', $factory->shared('request'));
        });

        $this->assertNull($factory->shared('request'));
    }

    public function testNestedScopesRestoreTheExactPreviousStateAfterFailure(): void
    {
        RequestSharedData::scope(['value' => 'outer'], function (): void {
            try {
                RequestSharedData::scope(['value' => 'inner'], function (): never {
                    throw new RuntimeException('failed');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('failed', $exception->getMessage());
            }

            $this->assertSame(['value' => 'outer'], RequestSharedData::all());
        });

        $this->assertSame([], RequestSharedData::all());
    }

    public function testSessionErrorsAreIsolatedBetweenConcurrentRequests(): void
    {
        $factory = $this->factory();
        $middleware = new ShareErrorsFromSession;
        $firstErrors = new ViewErrorBag;
        $secondErrors = new ViewErrorBag;
        $firstEntered = new Channel(1);
        $releaseFirst = new Channel(1);

        $responses = parallel([
            'first' => function () use ($factory, $middleware, $firstErrors, $firstEntered, $releaseFirst): Response {
                return $middleware->handle(
                    $this->requestWithErrors($firstErrors),
                    function (Request $request) use ($factory, $firstErrors, $firstEntered, $releaseFirst): Response {
                        $firstEntered->push(true);
                        $releaseFirst->pop();

                        return new Response($factory->shared('errors') === $firstErrors ? 'first' : 'leaked');
                    },
                );
            },
            'second' => function () use ($factory, $middleware, $secondErrors, $firstEntered, $releaseFirst): Response {
                $firstEntered->pop();

                return $middleware->handle(
                    $this->requestWithErrors($secondErrors),
                    function (Request $request) use ($factory, $secondErrors, $releaseFirst): Response {
                        $response = new Response($factory->shared('errors') === $secondErrors ? 'second' : 'leaked');
                        $releaseFirst->push(true);

                        return $response;
                    },
                );
            },
        ]);

        $firstEntered->close();
        $releaseFirst->close();

        $this->assertSame('first', $responses['first']->getContent());
        $this->assertSame('second', $responses['second']->getContent());
        $this->assertNull($factory->shared('errors'));
    }

    private function factory(): Factory
    {
        return new Factory(
            m::mock(EngineResolver::class),
            m::mock(ViewFinderInterface::class),
            m::mock(Dispatcher::class),
        );
    }

    private function requestWithErrors(ViewErrorBag $errors): Request
    {
        $session = m::mock(Session::class);
        $session->shouldReceive('get')->once()->with('errors')->andReturn($errors);

        $request = Request::create('/');
        $request->setHypervelSession($session);

        return $request;
    }
}
