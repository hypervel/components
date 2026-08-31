<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\Request;
use Hypervel\Inertia\DeferProp;

class DeferPropTest extends TestCase
{
    public function testCanInvoke(): void
    {
        $deferProp = new DeferProp(function () {
            return 'A deferred value';
        }, 'default');

        $this->assertSame('A deferred value', $deferProp());
        $this->assertSame('default', $deferProp->group());
    }

    public function testCallableArraysAndStaticMethodStringsAreInvoked(): void
    {
        $arrayProp = new DeferProp([DeferCallableFixture::class, 'resolve']);
        $stringProp = new DeferProp(DeferCallableFixture::class . '::resolve');

        $this->assertInstanceOf(Request::class, $arrayProp());
        $this->assertInstanceOf(Request::class, $stringProp());
    }

    public function testCanInvokeAndMerge(): void
    {
        $deferProp = (new DeferProp(function () {
            return 'A deferred value';
        }))->merge();

        $this->assertSame('A deferred value', $deferProp());
    }

    public function testCanResolveBindingsWhenInvoked(): void
    {
        $deferProp = new DeferProp(function (Request $request) {
            return $request;
        });

        $this->assertInstanceOf(Request::class, $deferProp());
    }

    public function testIsOnceable(): void
    {
        $deferProp = (new DeferProp(fn () => 'value'))
            ->once(as: 'custom-key', until: 60);

        $this->assertTrue($deferProp->shouldResolveOnce());
        $this->assertSame('custom-key', $deferProp->getKey());
        $this->assertNotNull($deferProp->expiresAt());
    }

    public function testCanBeMarkedAsRescuable(): void
    {
        $deferProp = new DeferProp(fn () => 'value', rescue: true);

        $this->assertTrue($deferProp->shouldRescue());
    }
}

class DeferCallableFixture
{
    public static function resolve(Request $request): Request
    {
        return $request;
    }
}
