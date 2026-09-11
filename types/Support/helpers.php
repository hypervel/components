<?php

declare(strict_types=1);

use DateTimeImmutable;
use Exception;

use function PHPStan\Testing\assertType;

/** @var null|bool|float|int|string $value */
if (filled($value)) {
    assertType('bool|float|int|non-empty-string', $value);
} else {
    assertType('string|null', $value);
}

if (blank($value)) {
    assertType('string|null', $value);
} else {
    assertType('bool|float|int|non-empty-string', $value);
}

assertType('User', object_get(new User, null));
assertType('User', object_get(new User, ''));
assertType('mixed', object_get(new User, 'name'));

assertType('1', once(fn () => 1));
assertType('null', once(function () { /* @phpstan-ignore function.void (testing void) */
}));

assertType('Hypervel\Support\Optional', optional());
assertType('null', optional(null, fn () => 1));
assertType('1', optional('foo', function ($value) {
    assertType("'foo'", $value);

    return 1;
}));

assertType('1', retry(5, fn () => 1));

assertType('object', str());
assertType('Hypervel\Support\Stringable', str('foo'));

assertType('User', tap(new User, function ($user) {
    assertType('User', $user);
}));
assertType('Hypervel\Support\HigherOrderTapProxy<User>', tap(new User));

/**
 * Check narrowing after conditional exceptions.
 */
function testThrowIf(float|int $foo, ?DateTimeImmutable $bar = null): void
{
    rescue(fn () => assertType('never', throw_if(true, Exception::class)));
    assertType('false', throw_if(false, Exception::class));
    assertType('false', throw_if(empty($foo)));
    throw_if(is_float($foo));
    assertType('int', $foo);
    throw_if($foo === 0);
    assertType('int<min, -1>|int<1, max>', $foo);

    // Truthy/falsey argument
    throw_if($bar);
    assertType('null', $bar);
    assertType('null', throw_if(null, Exception::class));
    assertType("''", throw_if('', Exception::class));
    rescue(fn () => assertType('never', throw_if('foo', Exception::class)));
}

/**
 * Check narrowing after inverse conditional exceptions.
 */
function testThrowUnless(float|int $foo, ?DateTimeImmutable $bar = null): void
{
    assertType('true', throw_unless(true, Exception::class));
    rescue(fn () => assertType('never', throw_unless(false, Exception::class)));
    assertType('true', throw_unless(empty($foo)));
    throw_unless(is_int($foo));
    assertType('int', $foo);
    throw_unless($foo === 0);
    assertType('0', $foo);
    throw_unless($bar instanceof DateTimeImmutable);
    assertType('DateTimeImmutable', $bar);

    // Truthy/falsey argument
    rescue(fn () => assertType('never', throw_unless(null, Exception::class)));
    rescue(fn () => assertType('never', throw_unless('', Exception::class)));
    assertType("'foo'", throw_unless('foo', Exception::class));
}

assertType('1', transform('filled', fn () => 1, true));
assertType('1', transform(['filled'], fn () => 1));
assertType('null', transform('', fn () => 1));
assertType('true', transform('', fn () => 1, true));
assertType('true', transform('', fn () => 1, fn () => true));

assertType('User', with(new User));
assertType('bool', with(new User)->save());
assertType('10', with(new User, function ($user) {
    assertType('User', $user);

    return 10;
}));
