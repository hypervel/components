<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Lazy;

use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Lazy\InertiaLazy;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Testbench\TestCase;

class InertiaLazyTest extends TestCase
{
    // REMOVED: 'resolves to a LazyProp on Inertia v2' and the version-dependent case; Hypervel's Inertia adapter
    // follows Inertia v3, which has no LazyProp.

    public function testResolvesToAnOptionalPropOnInertiaV3(): void
    {
        $lazy = Lazy::inertia(static fn (): string => 'value');

        $this->assertInstanceOf(InertiaLazy::class, $lazy);
        $this->assertTrue($lazy->shouldBeIncluded());
        $this->assertFalse($lazy->resolvesToData());
        $this->assertInstanceOf(OptionalProp::class, $lazy->resolve());
        $this->assertSame('value', ($lazy->resolve())());
    }
}
