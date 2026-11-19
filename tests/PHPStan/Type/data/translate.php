<?php

declare(strict_types=1);

namespace Translate;

use function PHPStan\Testing\assertType;

/**
 * @param list<string> $keys
 * @param non-empty-list<string> $someKeys
 * @param array<string, string> $named
 * @param array{key: string, locale?: string} $titled
 */
function test(?string $key, array $keys, array $someKeys, array $named, array $titled): void
{
    assertType('(array|string)', trans('foo'));
    assertType('(array|string)', trans(key: 'foo'));
    assertType('(array|string)', trans(...['foo', [], 'en']));
    assertType('(array|string)', trans('Hi :name', ['name' => 'Niek']));

    assertType('null', __());
    assertType('(array|string)', __('foo'));
    assertType('(array|string)', __('Hi :name', ['name' => 'Niek']));

    assertType('null', __(null));
    assertType('array|string|null', __($key));

    // An empty unpacked list leaves the key null.
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys));
    assertType('(array|string)', trans(...$someKeys));
    assertType('array|string|null', __(...$keys));
    assertType('(array|string)', __(...$someKeys));
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys, replace: []));

    // The key is omitted only when every leading unpacked list is empty.
    assertType('array|Hypervel\Translation\Translator|string', trans(...$keys, ...$keys));
    assertType('(array|string)', trans(...$keys, ...$someKeys));

    // A string-keyed unpack with the key passes it by name.
    assertType('(array|string)', trans(...['key' => 'title']));
    assertType('(array|string)', __(...['key' => 'title']));
    assertType('(array|string)', trans(...$titled));
    assertType('(array|string)', __(...$titled));

    // Hypervel: known PHPStan limitation. String keys name parameters, so this may omit the key too.
    assertType('array|string', trans(...$named));

    // An empty leading unpack supplies nothing, so the next one supplies the key.
    assertType('null', __(...[]));
    assertType('(array|string)', __(...[], ...['title']));

    if ($keys === []) {
        assertType('(array|string)', trans(...$keys, ...['title']));
    }
}
