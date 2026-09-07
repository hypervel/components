<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\ProvidesInertiaProperties;
use Hypervel\Inertia\RenderContext;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ResponseFactory;
use Hypervel\Inertia\Testing\AssertableInertia;

class HelperTest extends TestCase
{
    public function testTheHelperFunctionReturnsAnInstanceOfTheResponseFactory(): void
    {
        $this->assertInstanceOf(ResponseFactory::class, inertia());
    }

    public function testTheHelperFunctionReturnsAResponseInstance(): void
    {
        $this->assertInstanceOf(Response::class, inertia('User/Edit', ['user' => ['name' => 'Jonathan']]));
    }

    public function testTheHelperFunctionAcceptsArrayableProps(): void
    {
        $response = $this->makeMockRequest(inertia('User/Edit', collect(['name' => 'Jonathan'])));

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('name', 'Jonathan'));
    }

    public function testTheHelperFunctionAcceptsInertiaPropertyProviders(): void
    {
        $provider = new class implements ProvidesInertiaProperties {
            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ['name' => 'Jonathan', 'component' => $context->component];
            }
        };

        $response = $this->makeMockRequest(inertia('User/Edit', $provider));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('name', 'Jonathan')
            ->where('component', 'User/Edit'));
    }

    public function testTheHelperFunctionDelegatesEveryStringComponent(): void
    {
        $this->assertInstanceOf(Response::class, inertia(''));
        $this->assertInstanceOf(Response::class, inertia('0'));
    }

    public function testTheInstanceIsTheSameAsTheFacadeInstance(): void
    {
        Inertia::share('key', 'value');
        $this->assertEquals('value', inertia()->getShared('key'));
    }
}
