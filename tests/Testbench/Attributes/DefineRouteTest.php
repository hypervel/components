<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\TestCase;
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

        $router = $this->app->make(Router::class);

        $callback = $attribute->handle($this->app, function ($method, $parameters) use ($router): void {
            $this->assertSame('defineCallback', $method);
            $this->assertSame([$router], $parameters);
        });

        $this->assertNull($callback);
    }
}
