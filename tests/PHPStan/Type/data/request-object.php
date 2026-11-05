<?php

declare(strict_types=1);

namespace RequestObject;

use Hypervel\Http\Request;

use function PHPStan\Testing\assertType;

/**
 * Hypervel: the request declares these types in source. Stored query, post and cookie
 * values can be any scalar, array or Stringable, and their keys can be integers. A file
 * input stored as null is returned even when a default is given.
 */
function test(Request $request): void
{
    assertType('array<array<mixed>|Hypervel\Http\UploadedFile|null>', $request->file());
    assertType('array<mixed>|Hypervel\Http\UploadedFile|null', $request->file('foo'));
    assertType('array<mixed>|Hypervel\Http\UploadedFile|stdClass|null', $request->file('foo', new \stdClass()));
    assertType('123|array<mixed>|Hypervel\Http\UploadedFile|null', $request->file('foo', fn () => 123));

    assertType('Hypervel\Routing\Route|null', $request->route());
    assertType('mixed', $request->route('foo'));
    assertType('mixed', $request->route('foo', 'bar'));

    assertType('array<string, mixed>', $request->server());
    assertType('mixed', $request->server('foo'));

    assertType('array<mixed>', $request->post());
    assertType('array<mixed>|bool|float|int|string|Stringable|null', $request->post('foo'));
    assertType('array<mixed>|bool|float|int|string|Stringable', $request->post('foo', 'bar'));

    assertType('array<mixed>', $request->query());
    assertType('array<mixed>|bool|float|int|string|Stringable|null', $request->query('foo'));
    assertType('array<mixed>|bool|float|int|string|Stringable', $request->query('foo', 'bar'));

    assertType('array<mixed>', $request->cookie());
    assertType('array<mixed>|bool|float|int|string|Stringable|null', $request->cookie('foo'));
}
