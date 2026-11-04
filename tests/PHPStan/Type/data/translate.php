<?php

declare(strict_types=1);

namespace Translate;

use function PHPStan\Testing\assertType;

function test(?string $key): void
{
    // trans() returns the concrete translator, as in Laravel; upstream's helper stub declares the contract.
    assertType('Hypervel\Translation\Translator', trans());
    assertType('(array|string)', trans('foo'));
    assertType('(array|string)', trans('Hi :name', ['name' => 'Niek']));

    assertType('null', __());
    assertType('(array|string)', __('foo'));
    assertType('(array|string)', __('Hi :name', ['name' => 'Niek']));

    assertType('Hypervel\Translation\Translator', trans(null));
    assertType('array|Hypervel\Translation\Translator|string', trans($key));
    assertType('null', __(null));
    assertType('array|string|null', __($key));
}
