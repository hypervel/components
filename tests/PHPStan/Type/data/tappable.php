<?php

namespace Tappable;

use Hypervel\Support\Traits\Tappable;

use function PHPStan\Testing\assertType;

class TappableClass
{
    use Tappable;

    public function name(): string
    {
        return '';
    }

    public function tapped(): void
    {
        // Hypervel: calls through the proxy return the tapped object itself.
        assertType('$this(Tappable\TappableClass)', $this->tap()->name());
    }
}

function test(): void
{
    assertType(
        'Tappable\TappableClass',
        (new TappableClass())->tap(function (TappableClass $tappable) {
        }),
    );

    assertType(
        'Hypervel\Support\HigherOrderTapProxy<Tappable\TappableClass>',
        (new TappableClass())->tap(),
    );
}
