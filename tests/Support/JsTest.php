<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Js;
use Hypervel\Tests\TestCase;

enum JsTestStringEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum JsTestUnitEnum
{
    case pending;
    case completed;
}

enum JsTestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

/**
 * @internal
 * @coversNothing
 */
class JsTest extends TestCase
{
    public function testFromWithStringBackedEnum(): void
    {
        $js = Js::from(JsTestStringEnum::Active);

        $this->assertSame("'active'", (string) $js);
    }

    public function testFromWithUnitEnum(): void
    {
        $js = Js::from(JsTestUnitEnum::pending);

        $this->assertSame("'pending'", (string) $js);
    }

    public function testFromWithIntBackedEnum(): void
    {
        $js = Js::from(JsTestIntEnum::One);

        $this->assertSame('1', (string) $js);
    }

    public function testFromWithString(): void
    {
        $js = Js::from('hello');

        $this->assertSame("'hello'", (string) $js);
    }

    public function testFromWithInteger(): void
    {
        $js = Js::from(42);

        $this->assertSame('42', (string) $js);
    }

    public function testFromWithArray(): void
    {
        $js = Js::from(['foo' => 'bar']);

        $this->assertStringContainsString('JSON.parse', (string) $js);
    }

    public function testFromWithNull(): void
    {
        $js = Js::from(null);

        $this->assertSame('null', (string) $js);
    }

    public function testFromWithBoolean(): void
    {
        $js = Js::from(true);

        $this->assertSame('true', (string) $js);
    }
}
