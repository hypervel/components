<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\Request;
use Hypervel\Inertia\DeferProp;

/**
 * @internal
 * @coversNothing
 */
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

    public function testStringFunctionNamesAreNotInvoked(): void
    {
        $deferProp = new DeferProp('date');

        $this->assertSame('date', $deferProp());
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
}
