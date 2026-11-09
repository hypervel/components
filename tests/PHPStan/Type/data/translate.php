<?php

declare(strict_types=1);

namespace Translate;

use function PHPStan\Testing\assertType;

/**
 * @param list<string> $keys
 * @param non-empty-list<string> $someKeys
 * @param array<string, string> $named
 */
function test(?string $key, array $keys, array $someKeys, array $named): void
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

    // An empty unpacked list leaves the key null.
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys));
    assertType('array|string', trans(...$someKeys));
    assertType('array|string|null', __(...$keys));
    assertType('array|string', __(...$someKeys));
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys, replace: []));

    // The key is omitted only when every leading unpacked list is empty.
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys, ...$keys));
    assertType('array|string', trans(...$keys, ...$someKeys));

    // Hypervel: known PHPStan limitation. String keys name parameters, so this may omit the key too.
    assertType('array|string', trans(...$named));
}
