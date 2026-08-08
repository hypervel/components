<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ResponseFactory;

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
