<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\Request;
use Hypervel\Inertia\OptionalProp;

class OptionalPropTest extends TestCase
{
    public function testCanInvoke(): void
    {
        $optionalProp = new OptionalProp(function () {
            return 'An optional value';
        });

        $this->assertSame('An optional value', $optionalProp());
    }

    public function testStringFunctionNamesAreNotInvoked(): void
    {
        $optionalProp = new OptionalProp('date');

        $this->assertSame('date', $optionalProp());
    }

    public function testCanResolveBindingsWhenInvoked(): void
    {
        $optionalProp = new OptionalProp(function (Request $request) {
            return $request;
        });

        $this->assertInstanceOf(Request::class, $optionalProp());
    }

    public function testIsOnceable(): void
    {
        $optionalProp = (new OptionalProp(fn () => 'value'))
            ->once()
            ->as('custom-key')
            ->until(60);

        $this->assertTrue($optionalProp->shouldResolveOnce());
        $this->assertSame('custom-key', $optionalProp->getKey());
        $this->assertNotNull($optionalProp->expiresAt());
    }
}
