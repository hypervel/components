<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ResponseFactory;

/**
 * @internal
 * @coversNothing
 */
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

    public function testTheInstanceIsTheSameAsTheFacadeInstance(): void
    {
        Inertia::share('key', 'value');
        $this->assertEquals('value', inertia()->getShared('key'));
    }
}
