<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Closure;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DefineDatabaseTest extends TestCase
{
    #[Test]
    public function itCanResolveDefinition(): void
    {
        $attribute = new DefineDatabase('defineCallback', defer: false);

        $this->assertInstanceOf(DefineDatabase::class, $attribute);
        $this->assertSame('defineCallback', $attribute->method);
        $this->assertFalse($attribute->defer);
    }

    #[Test]
    public function itCanHandleDeferDefinition(): void
    {
        $attribute = new DefineDatabase('defineCallback', defer: true);

        $this->assertInstanceOf(DefineDatabase::class, $attribute);
        $this->assertSame('defineCallback', $attribute->method);
        $this->assertTrue($attribute->defer);

        $callback = $attribute->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('defineCallback', $method);
            $this->assertSame([$this->app], $parameters);
        });

        $this->assertInstanceOf(Closure::class, $callback);

        $callback();
    }

    #[Test]
    public function itCanHandleEagerDefinition(): void
    {
        $attribute = new DefineDatabase('defineCallback', defer: false);

        $this->assertInstanceOf(DefineDatabase::class, $attribute);
        $this->assertSame('defineCallback', $attribute->method);
        $this->assertFalse($attribute->defer);

        $callback = $attribute->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('defineCallback', $method);
            $this->assertSame([$this->app], $parameters);
        });

        $this->assertNull($callback);
    }
}
