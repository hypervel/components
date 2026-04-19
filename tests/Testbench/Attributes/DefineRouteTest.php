<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class DefineRouteTest extends TestCase
{
    #[Test]
    public function itCanResolveDefinition(): void
    {
        $attribute = new DefineRoute('defineCallback');

        $this->assertInstanceOf(DefineRoute::class, $attribute);
        $this->assertSame('defineCallback', $attribute->method);
    }

    #[Test]
    public function itCanHandleDefinition(): void
    {
        $attribute = new DefineRoute('defineCallback');

        $this->assertInstanceOf(DefineRoute::class, $attribute);
        $this->assertSame('defineCallback', $attribute->method);

        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('make')->with('router')->andReturn($router = m::mock(Router::class));

        $callback = $attribute->handle($app, function ($method, $parameters) use ($router): void {
            $this->assertSame('defineCallback', $method);
            $this->assertSame([$router], $parameters);
        });

        $this->assertNull($callback);
    }
}
