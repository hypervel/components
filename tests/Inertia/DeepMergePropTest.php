<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\Request;
use Hypervel\Inertia\MergeProp;

class DeepMergePropTest extends TestCase
{
    public function testCanInvokeWithACallback(): void
    {
        $mergeProp = (new MergeProp(fn () => 'A merge prop value'))->deepMerge();

        $this->assertSame('A merge prop value', $mergeProp());
    }

    public function testCanInvokeWithANonCallback(): void
    {
        $mergeProp = (new MergeProp(['key' => 'value']))->deepMerge();

        $this->assertSame(['key' => 'value'], $mergeProp());
    }

    public function testCanResolveBindingsWhenInvoked(): void
    {
        $mergeProp = (new MergeProp(fn (Request $request) => $request))->deepMerge();

        $this->assertInstanceOf(Request::class, $mergeProp());
    }

    public function testCanUseSingleStringAsKeyToMatchOn(): void
    {
        $mergeProp = (new MergeProp(['key' => 'value']))->matchOn('key');

        $this->assertEquals(['key'], $mergeProp->matchesOn());
    }

    public function testCanUseAnArrayOfStringsAsKeysToMatchOn(): void
    {
        $mergeProp = (new MergeProp(['key' => 'value']))->matchOn(['key', 'anotherKey']);

        $this->assertEquals(['key', 'anotherKey'], $mergeProp->matchesOn());
    }
}
