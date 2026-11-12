<?php

namespace OptionalType;

use StdClass;

use function optional;
use function PHPStan\Testing\assertType;

class Foo
{
    public function doFoo(): void { }
}

class Bar
{
    public function name(): string
    {
        return '';
    }

    public function fill(int &$value): void
    {
        $value = 42;
    }

    private function secret(): string
    {
        return '';
    }

    public function optionalMacro(): string
    {
        return '';
    }
}

function test(?Bar $maybeBar, Bar|string $barOrString, mixed $mixed): void
{
    assertType('Hypervel\Support\Optional<array{foo: string}>', optional(['foo' => 'bar']));
    assertType('1', optional('1', function (string $value): int {
        return 1;
    }));
    assertType('null', optional(null, function (string $value): int {
        return 1;
    }));
    assertType('Hypervel\Support\Optional<StdClass>', optional(new StdClass()));
    assertType('mixed', optional(new StdClass())->foo);
    assertType('null', optional(new Foo)->doFoo());

    // Hypervel: method calls go to the object the Optional holds, and return null when it may hold something else.
    assertType('string', optional(new Bar)->name());
    assertType('string|null', optional($maybeBar)->name());
    assertType('string|null', optional($barOrString)->name());
    assertType('mixed', optional($mixed)->anything());
    assertType('null', optional(null)->anything());
    assertType('mixed', optional($maybeBar)['key']);

    // Hypervel: proxies reach only public methods, and pass argument values rather than references.
    assertType('*ERROR*', optional(new Bar)->secret());
    $value = 1;
    optional(new Bar)->fill($value);
    assertType('1', $value);

    // Hypervel: an Optional macro runs before the held object's method of the same name, whatever the value.
    assertType('int', optional(new Bar)->optionalMacro());
    assertType('int', optional(null)->optionalMacro());
}

/**
 * Hypervel: the callback only receives a value that is not null.
 */
function callbacks(?Bar $maybeBar, ?int $maybeInt): void
{
    optional($maybeBar, function ($bar) {
        assertType('OptionalType\Bar', $bar);
    });
    optional($maybeInt, function ($value) {
        assertType('int', $value);
    });
    assertType('string|null', optional($maybeBar, fn (Bar $bar): string => $bar->name()));
    assertType('Hypervel\Support\Optional<OptionalType\Bar|null>', optional($maybeBar));
}
